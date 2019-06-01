<?php

return [

    /**
     * The desired format for the generated document.
     * Accepted values are yaml and json.
     */
    'format' => 'yaml',

     /**
     * The desired format for the generated document.
     * Accepted values are yaml and json.
     */
   'output_path' => base_path(),

    /**
     * The OpenApi Specification version to use.
     * Accepted values are 2 and 3.
     */
    'version' => '2',

    'filename' => 'openapi',

    'write' => true,

    'print' => false,

];
