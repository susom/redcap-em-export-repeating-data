<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    if (!isset($_POST)) {
        throw new \LogicException('You cant be here');
    }

    /**
     * Build the data structures required to render the client
     */

    $module->getClientMetadataObject()->getClientMetadata();

} catch (\LogicException $e) {
    $module->emError($e->getMessage());
   // echo $e->getMessage();
}
?>