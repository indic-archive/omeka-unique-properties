<?php

declare(strict_types=1);

namespace UniqueProperties;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'form_elements' => [
        'invokables' => [
            # module configuration form
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],

    'uniqueproperties' => [
        'config' => [
            'unique_properties' => [],
        ],
    ],
];
