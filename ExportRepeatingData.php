<?php
namespace Stanford\ExportRepeatingData;
use REDCap;

require_once "emLoggerTrait.php";
ini_set('max_execution_time', 0);
set_time_limit(0);
require_once(__DIR__ . "/server/InstrumentMetadata.php");
require_once(__DIR__ . "/server/Export.php");
require_once(__DIR__ . "/server/ClientMetadata.php");


define('DEFAULT_NUMBER_OF_CACHE_DAYS', 5);

/**
 * Class ExportRepeatingData
 * @package Stanford\ExportRepeatingData
 * @property \Stanford\ExportRepeatingData\InstrumentMetadata $instrumentMetadata
 * @property \Stanford\ExportRepeatingData\Export $export
 * @property array $inputs
 * @property array $patientFilter
 */
class ExportRepeatingData extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $project;

    private $instrumentMetadata;

    private $dataDictionary = array();

    private $export;

    private $clientMetadata;

    private $pathPrefix;

    private $userRights;

    /**
     *  constructor.
     */
    public function __construct()
    {
        parent::__construct();

        try {

            if (isset($_GET['pid'])) {

                $this->setProject(new \Project(filter_var($_GET['pid'], FILTER_SANITIZE_NUMBER_INT)));
                $this->setEventId($this->getFirstEventId());
                $this->userRights = REDCap::getUserRights(USERID)[USERID];
                $dataDictionary = $this->applyUserViewingRights($this->project->metadata);
                $this->setDataDictionary($dataDictionary);
                $referer  = $_SERVER['HTTP_REFERER'];
                $indexOf4thslash = $this->strposX($referer, "/", 4);
                $this->pathPrefix = substr($referer, 0, $indexOf4thslash);
                $this->instrumentMetadata = new InstrumentMetadata($this->getProject()->project_id, $this->getDataDictionary());
                $this->setExport(new Export($this->getProject(), $this->instrumentMetadata, $this->getProjectSetting("temp-file-days-to-expire") ? $this->getProjectSetting("temp-file-days-to-expire") : DEFAULT_NUMBER_OF_CACHE_DAYS, $this->getProjectSetting("temp-file-config")));
                $this->clientMetadata = new ClientMetadata();
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private function applyUserViewingRights($dataDictionary) {
        if (in_array('0',$this->userRights['forms'])) {
            foreach ($dataDictionary as $field_name => $field_info) {
                if (!$this->userRights['forms'][$field_info['form_name']]) {
                    unset($dataDictionary[$field_name]);
                }
            }
        }
        return $dataDictionary;
    }

    public function prepareTempFile()
    {
        // if path changed or created then save it and new timestamp
        if ($this->export->getTempFileConfig() != $this->getProjectSetting('temp-file-config')) {
            $this->setProjectSetting('temp-file-config', $this->export->getTempFileConfig());
        }
    }

    /**
     * Find the position of the Xth occurrence of a substring in a string
     * @param $haystack
     * @param $needle
     * @param $number integer > 0
     * @return int
     */
    private function strposX($haystack, $needle, $number)
    {
        if ($number == '1') {
            return strpos($haystack, $needle);
        } elseif($number > '1') {
            return strpos($haystack, $needle, $this->strposX($haystack, $needle, $number - 1) + strlen($needle));
        } else {
            return error_log('Error: Value for parameter $number is out of range');
        }
    }

    /**
     * Only display the Generate DB Data link for Susan and Srini
     * @param $project_id
     * @param $link
     * @param null $record
     * @param null $instrument
     * @param null $instance
     * @param null $page
     * @return bool|null
     */
    public function redcap_module_link_check_display($project_id, $link, $record = null, $instrument = null, $instance = null, $page = null) {
        $result = false;

        // Evaluate all links for now - in the future you might have different rules for different links...
        if (@$link['name'] == "Generate DB Data" && !empty($project_id)) {
            global $userid;
            // Hide this link from the general public
            if ($userid == 'scweber' || $userid == 'sboosi' ) $result = $link;
        } else {
            $result = $link;
        }
        return $result;
    }

    /**
     * @return array
     */
    public function getUserRights() {
        return $this->userRights;
    }

    /**
     * @return string
     */
    public function getPrefix() {
        return $this->pathPrefix;
    }

    /**
     * @return array
     */
    public function getInstrumentNames() {
        return $this->instrumentMetadata->getInstrumentNames();
    }

    /**
     * @return array
     */
    public function getFieldNames($instrument) {
        return $this->instrumentMetadata->getFieldNames($instrument);
    }

    /**
     * @return array
     */
    public function getDataDictionary()
    {
        return $this->dataDictionary;
    }

    /**
     * @param array $dataDictionary
     */
    public function setDataDictionary($dataDictionary)
    {
        $this->dataDictionary = $dataDictionary;
    }

    /**
     * @return array
     */
    public function getDataDictionaryProp($prop)
    {
        return $this->dataDictionary[$prop];
    }
    /**
     * @return string
     */
    public function getPatientFilterText()
    {
        return $this->patientFilterText;
    }

    /**
     * @param string $patientFilterText
     */
    public function setPatientFilterText($patientFilterText)
    {
        $this->patientFilterText = $patientFilterText;
    }

    /**
     * @return mixed
     */
    public function getCurrentPageNumber()
    {
        return $this->currentPageNumber;
    }

    /**
     * @param mixed $currentPageNumber
     */
    public function setCurrentPageNumber($currentPageNumber)
    {
        $this->currentPageNumber = $currentPageNumber;
    }

    /**
     * @return mixed
     */
    public function getPatientFilter()
    {
        return $this->patientFilter;
    }

    /**
     * @param mixed $patientFilter
     */
    public function setPatientFilter($patientFilter)
    {
        $this->patientFilter = $patientFilter;
    }

    /**
     * @return mixed
     */
    public function getEventId()
    {
        return $this->eventId;
    }

    /**
     * @param mixed $eventId
     */
    public function setEventId($eventId)
    {
        $this->eventId = $eventId;
    }

    /**
     * @return mixed
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param $project
     */
    public function setProject($project)
    {
        $this->project = $project;
        $this->setRepeatingFormsEvents();
    }

    private function setRepeatingFormsEvents()
    {
        $this->project->setRepeatingFormsEvents();
    }

    public function isRepeatingForm($key)
    {
        return ($this->instrumentMetadata->isRepeating($key)['cardinality'] == 'repeating') ;
    }

    public function getDateField($key)
    {
        return $this->instrumentMetadata->getDateField($key);
    }

    public function getValue($key)
    {
        return $this->instrumentMetadata->getValue($key);
    }

    public function isInstanceSelectLinked($key)
    {
        return $this->instrumentMetadata->isInstanceSelectLinked($key);
    }

    public function instanceSelectLink($key)
    {
        return $this->instrumentMetadata->instanceSelectLinked($key);
    }

    public function hasChild($instrument )
    {
        return $this->instrumentMetadata->hasChild( $instrument);
    }


    /**
     * Convert project metadata into a json payload for pickup via ajax from the UI
     * @param array $config
     */
    public function getClientMetadata()
    {
        try {
            $this->clientMetadata->getClientMetadata();
        } catch (\Exception $e) {
            echo $e->getMessage();
            $this->emError($e->getMessage());
            return "";
        }

    }
    public function getFilterDefns() {
        try {
            $this->clientMetadata->getFilterDefns();
        } catch (\Exception $e) {
            echo $e->getMessage();
            $this->emError($e->getMessage());
            return "";
        }
    }

    /**
     * convert json to SQL, then send the data back to the client
     * for display in the browser.
     * @param array $config
     */
    public function displayContent($config)
    {
        if ($this->getExport()->isUseTempFile()) {
            $output = file_get_contents($this->getExport()->getTempFilePath());
        } else {
            // start debug setup part 1
            // microtime(true) returns the unix timestamp plus milliseconds as a float
            $starttime = microtime(true);
            $this->emDebug("displayContent launching SQL query");
            // end debug setup part 1

            $result = $this->export->buildAndRunQuery($config);

            // start debug setup part 2
            $endtime = microtime(true);
            $timediff = $endtime - $starttime;
            $this->emDebug("displayContent query returned in " . $this->secondsToTime($timediff));
            // end debug setup part 2

            // TODO consider trying to compress prior to sending, as this takes a while
            $output = json_encode($result);

            // save the output to the temp file initiated in the export object.
            if ($this->export->getTempFilePath()) {
                $this->export->saveTempFile($output);
            }
        }


        header("content-type: application/json");

        echo $output;
    }

    public function secondsToTime($s)
    {
        $h = floor($s / 3600);
        $s -= $h * 3600;
        $m = floor($s / 60);
        $s -= $m * 60;
        return $h . ':' . sprintf('%02d', $m) . ':' . sprintf('%02d', $s);
    }

    /**
     * @return Export
     */
    public function getExport()
    {
        return $this->export;
    }

    /**
     * @param Export $export
     */
    public function setExport(Export $export)
    {
        $this->export = $export;
    }

    public function processFiles()
    {
        $config = $this->getExport()->getTempFileConfig();
        $result = array();
        if ($config) {
            foreach ($config as $item) {
                if ($item['date'] && time() < strtotime($item['date'])) {
                    if (file_exists($item['path'])) {
                        $result[] = array('status' => 'available', 'path' => $item['path']);
                    } else {
                        $result[] = array('status' => 'processing', 'path' => $item['path']);
                    }
                }
            }
        }
        return $result;
    }

    public function prepareDataForDownload($data)
    {
        $result = array();
        foreach ($data as $item) {
            if (is_array($item[0])) {
                return $this->prepareDataForDownload($item);
            } else {
                $result[] = implode(",", $item);
            }

        }
        return implode("\n", $result);
    }

    public function downloadCSVFile($filename, $data)
    {
        $data = trim($this->prepareDataForDownload($data['data']));
        // Download file and then delete it from the server
        header('Pragma: anytextexeptno-cache', true);
        header('Content-Type: application/octet-stream"');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $data;
        exit();
    }


    public function manageReports()
    {
        $action = filter_var($_GET['action'], FILTER_SANITIZE_STRING);
        $reports = json_decode($this->getProjectSetting('saved-reports'), true);

        if ($action == 'save') {
            $name = filter_var($_GET['report_name'], FILTER_SANITIZE_STRING);
            $content = $_GET['report_content'];
            $reports[$name] = $content;
            $this->setProjectSetting('saved-reports', json_encode($reports));
        } elseif ($action == 'delete') {
            $name = filter_var($_GET['report_name'], FILTER_SANITIZE_STRING);
            unset($reports[$name]);
            $this->setProjectSetting('saved-reports', json_encode($reports));
        }
        echo json_encode(array('status' => 'success', 'reports' => json_encode($reports)));
    }
}
