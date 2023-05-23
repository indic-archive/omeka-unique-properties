<?php

declare(strict_types=1);

namespace DedupProperties;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
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
            $propIds = $settings->get('dedup_properties');

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

            $concatPropIds = array();
            for ($i = 0; $i < count($itemPropIDs) && $i < count($propValues); $i++) {
                $concatPropIds[] = $propValues[$i] . '|' . $itemPropIDs[$i];
            }
            $logger->info(new Message(print_r($itemId, true)));
            $logger->info(new Message(print_r($itemPropIDs, true)));
            $logger->info(new Message(print_r($propValues, true)));
            $logger->info(new Message(print_r($concatPropIds, true)));

            $services = $this->getServiceLocator();
            $connection = $services->get('Omeka\Connection');
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
    AND CONCAT_WS('|', `value`.`value`, `value`.`property_id`) IN (:concatenated_prop_ids_values)
SQL;
            $stmt = $connection->executeQuery($sql, [
                'id' => $itemId,
                'property_ids' => $itemPropIDs,
                'property_values' => $propValues,
                'concatenated_prop_ids_values' => $concatPropIds,
            ],[
                'property_ids' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
                'property_values' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
                'concatenated_prop_ids_values' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
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
