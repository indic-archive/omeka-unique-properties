<?php

declare(strict_types=1);

namespace UniqueProperties;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Stdlib\Message;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Api\Exception\ValidationException;


class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // add listeners for add/update
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.pre',
            [$this, 'dedupAction']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'dedupAction'],
        );
    }

    public function dedupAction(Event $event): void
    {
        $serviceLocator = $this->getServiceLocator();
        $logger = $serviceLocator->get('Omeka\Logger');
        $settings = $serviceLocator->get('Omeka\Settings');
        $messenger = new Messenger;

        $request = $event->getParam('request');
        if ($request->getOperation() == 'create' || $request->getOperation() == 'update') {
            $itemId = (int) $request->getId();
            $data = $request->getContent();
            $propIds = $settings->get('unique_properties');

            $allowedProps = array();
            foreach ($propIds as $propId) {
                $allowedProps[$propId] = true;
            }

            $itemPropIDs = array();
            // Fetch the property values from this request.
            $propValues = array();
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $innerKey => $innerValue) {
                        if (isset($innerValue['property_id']) && !empty($innerValue['@value'])) {
                            // ignore property ids that are not matching
                            if (!$allowedProps[$innerValue['property_id']]) {
                                continue;
                            }
                            array_push($propValues, $innerValue['@value']);
                            array_push($itemPropIDs, $innerValue['property_id']);
                        }
                    }
                }
            }

            // Convert property-ids, property-values into json
            // This allows us to load this into a derived table in MySQL
            $jsonPairs = json_encode(array_map(function ($value, $property_id) {
                return ['value' => $value, 'property_id' => $property_id];
            }, $propValues, $itemPropIDs), JSON_UNESCAPED_UNICODE);
            // $logger->info(new Message(print_r($jsonPairs, true)));

            $services = $this->getServiceLocator();
            $connection = $services->get('Omeka\Connection');

            // Check if same property-id, property-value exists in any other items.
            $sql = <<<'SQL'
SELECT
    CONCAT(
        `vocabulary`.`label`,
        ' - ',
        `property`.`label`
    ) as `field`,
    `value`.`value` as `data`,
    `value`.`resource_id` as `resource_id`
FROM
    `value`
    LEFT JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
    LEFT JOIN `property` ON `property`.`id` = `value`.`property_id`
    LEFT JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
WHERE
    `value`.`value` IN (:property_values)
    AND `value`.`resource_id` <> :id
    AND `value`.`property_id` IN (:property_ids)
    AND `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND EXISTS (
        SELECT 1
        FROM JSON_TABLE(
            :json_pairs,
            "$[*]" COLUMNS (
                value_prop VARCHAR(255) CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_unicode_ci' PATH "$.value",
                property_id INT PATH "$.property_id"
            )
        ) derived
        WHERE
            derived.value_prop = value.value
            AND derived.property_id = value.property_id
    )
SQL;
            $stmt = $connection->executeQuery($sql, [
                'id' => $itemId,
                'property_ids' => $itemPropIDs,
                'property_values' => $propValues,
                'json_pairs' => $jsonPairs,
            ], [
                'property_ids' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
                'property_values' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
                'json_pairs' => \PDO::PARAM_STR,
            ]);
            $results = $stmt->fetchAll();
            $logger->info(new Message(print_r($results, true)));

            foreach ($results as $result) {
                $messenger->addError(new Message('Duplicate property value `%s` found for `%s` in #%d', $result['data'], $result['field'], $result['resource_id']));
                throw new ValidationException('');
            }
        }
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $config = $this->getConfig();
        $space = strtolower(static::NAMESPACE);
        if (empty($config[$space]['config'])) {
            return true;
        }

        $services = $this->getServiceLocator();
        $formManager = $services->get('FormElementManager');
        $formClass = static::NAMESPACE . '\Form\ConfigForm';
        if (!$formManager->has($formClass)) {
            return true;
        }

        $params = $controller->getRequest()->getPost();

        $form = $formManager->get($formClass);
        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $params = $form->getData();
        $settings = $services->get('Omeka\Settings');

        $defaultSettings = $config[$space]['config'];
        $params = array_intersect_key($params, $defaultSettings);
        foreach ($params as $name => $value) {
            $settings->set($name, $value);
        }

        return true;
    }
}
