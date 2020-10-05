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

    private $instrumentNames;

    private $dataDictionary = array();

    private $export;

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
                $this->setDataDictionary(REDCap::getDataDictionary($this->getProject()->project_id, 'array'));;
                $this->instrumentNames = REDCap::getInstrumentNames();
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
    public function getInstrumentNames() {
        return $this->instrumentNames;
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
        return $this->getProject()->isRepeatingForm($this->getEventId(), $key);
    }

    public function getDateField($key)
    {
        return $this->instrumentMetadata->getDateField($key);
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
     * convert json to SQL, then send the data back to the client
     * for display in the browser. Only works with small datatsets
     * @param array $config
     */
    public function displayContent($config)
    {
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
        header("content-type: application/json");

        echo $output;
    }

    public function secondsToTime($s)
    {
        $h = floor($s / 3600);
        $s -= $h * 3600;
        $m = floor($s / 60);
        $s -= $m * 60;
        return $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
    }

}
