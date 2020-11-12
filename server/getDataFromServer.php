<?php

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
error_reporting( E_ALL ^ ( E_NOTICE | E_WARNING | E_DEPRECATED ) );


try {
    if (!isset($_POST)) {
        throw new \LogicException('You cant be here');
    }

    if (isset($_POST['cardinality'])) {
        // we only want to validate the temp file when exporting data.
        $module->getExport()->checkTempFile(json_encode($_POST));
        // now save the new temp file and date if changed;
        $module->prepareTempFile();
    }

    /**
     * Run the SQL corresponding to the user supplied spec
     */

    $module->displayContent($_POST);

} catch (\LogicException $e) {
    echo $e->getMessage();
}
?>
