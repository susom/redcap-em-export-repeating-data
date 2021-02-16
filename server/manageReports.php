<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    $module->manageReports();
} catch (\Exception  $e) {
    $module->emError($e->getMessage());
    http_response_code(404);
    echo $e->getMessage();
}

?>