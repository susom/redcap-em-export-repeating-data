<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    $action = filter_var($_GET['action'], FILTER_SANITIZE_STRING);
    $name = filter_var($_GET['report_name'], FILTER_SANITIZE_STRING);
    $content = $_GET['report_content'];
    $reports = json_decode($module->getProjectSetting('saved-reports'), true);
    $reports[$name] = $content;
    $module->setProjectSetting('saved-reports', json_encode($reports));
    echo json_encode(array('status' => 'success', 'reports' => json_encode($reports)));
} catch (\Exception  $e) {
    $module->emError($e->getMessage());
    http_response_code(404);
    echo $e->getMessage();
}

?>