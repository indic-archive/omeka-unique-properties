<?php

declare(strict_types=1);

namespace UniqueProperties\Form;

use Laminas\Form\Form;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name' => 'unique_properties',
            'type' => PropertySelect::class,
            'attributes' => [
                'id' => 'remove-property-values',
                'class' => 'chosen-select',
                'multiple' => true,
                'data-placeholder' => 'Select properties', // @translate
            ],
            'options' => [
                'label' => 'Unique property values', // @translate
            ],
        ]);
    }
}
