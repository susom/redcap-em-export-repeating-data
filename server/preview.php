<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    if (!isset($_POST)) {
        throw new \LogicException('You cant be here');
    }
    error_log(print_r($_POST,TRUE));
    /**
     * Run the SQL corresponding to the user supplied spec
     */
    $module->displayContent($_POST);

} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>