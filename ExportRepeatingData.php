<?php
namespace Stanford\ExportRepeatingData;

require_once "emLoggerTrait.php";
ini_set('max_execution_time', 0);
set_time_limit(0);
require_once(__DIR__ . "/client/classes/InstrumentMetadata.php");
require_once(__DIR__ . "/server/Export.php");

use REDCap;
use Stanford\ExportRepeatingData\emLoggerTrait;
use Stanford\ExportRepeatingData\InstrumentMetadata;
use Stanford\ExportRepeatingData\Export;

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

    /**
     *  constructor.
     */
    public function __construct()
    {
        parent::__construct();

        try {
            error_log("hello from the ExportRepeatingData constructor ". $_GET['pid']);
            if (isset($_GET['pid'])) {

                $this->setProject(new \Project(filter_var($_GET['pid'], FILTER_SANITIZE_NUMBER_INT)));
                $this->setEventId($this->getFirstEventId());
                $this->setDataDictionary(REDCap::getDataDictionary($this->getProject()->project_id, 'array'));;

                $this->instrumentMetadata = new InstrumentMetadata($this->getProject()->project_id, $this->getDataDictionary());
                $this->export = new Export($this->getProject(), $this->instrumentMetadata);

            }

        } catch (\Exception $e) {
            echo $e->getMessage();
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
        return $this->getProject()->isRepeatingForm($this->getEventId(), $key);
    }

    /**
     * convert json to SQL, then send back to the client as
     * a streaming download file, so as not to run the browser
     * out of memory for very large files
     * @param array $config
     */
    public function exportToFile($config)
    {
    }

    /**
     * convert json to SQL, then send the data back to the client
     * for display in the browser. Only works with small datatsets
     * @param array $config
     */
    public function displayContent($config)
    {
        $supportsGzip = strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;
        error_log('support for gzip? '. $supportsGzip);
//        //clean for last time for before display
//        $this->cleanColumns();
        $result = $this->export->buildAndRunQuery($config);
        // $result1 is already json encoded
        error_log('result is ' .print_r($result,TRUE));
//        error_log(print_r($this->getProject(), TRUE));
//        error_log('person repeats? ' . $this->isRepeatingForm('person'));
//        error_log('meds repeats? ' . $this->isRepeatingForm('meds'));

        //TODO add client side support for gzip
//        if ($supportsGzip) {
//            $output = gzencode(trim(preg_replace('/\s+/', ' ',
//                json_encode($result, JSON_UNESCAPED_UNICODE))), 9);
//            header("content-encoding: gzip");
//            ob_start("ob_gzhandler");
//        } else {
            $output = json_encode($result);
//        }
        //$output = json_encode($result);
        $offset = 60 * 60;
        $expire = "expires: " . gmdate("D, d M Y H:i:s", time() + $offset) . " GMT";
        header("content-type: application/json");
        header("cache-control: must-revalidate");
        header($expire);
        header('Content-Length: ' . strlen($output));
        header('Vary: Accept-Encoding');
        echo $output;
        ob_end_flush();
    }


}
