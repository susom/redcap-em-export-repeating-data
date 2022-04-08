<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


try {
    /**
     * @psalm-taint-escape html
     */
    $file = filter_var($_REQUEST['file'], FILTER_SANITIZE_STRING);
    if (file_exists($file)) {
        $name = explode("/", $file);
        $name = end($name);
        $output = json_decode(file_get_contents($file), true);
        unset($output['status']);
        $module->downloadCSVFile($name, $output);
    } else {
        throw new \Exception("file does not exist");
    }
} catch (\Exception  $e) {
    $module->emError($e->getMessage());
    http_response_code(404);
    echo $e->getMessage();
}

?>
