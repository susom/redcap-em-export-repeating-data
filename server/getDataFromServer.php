<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    if (!isset($_POST)) {
        throw new \LogicException('You cant be here');
    }

    /**
     * Run the SQL corresponding to the user supplied spec
     */
    $module->displayContent($_POST);

} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>