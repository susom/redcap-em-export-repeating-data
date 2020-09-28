<?php
namespace Stanford\ExportRepeatingData;

require_once "emLoggerTrait.php";
ini_set('max_execution_time', 0);
set_time_limit(0);
require_once(__DIR__ . "/client/classes/InstrumentMetadata.php");

use REDCap;
use Stanford\ExportRepeatingData\emLoggerTrait;
use Stanford\ExportRepeatingData\InstrumentMetadata;

define('PRIMARY_INSTRUMENT', 'primary-instrument');
define('SECONDARY_INSTRUMENT', 'secondary-instrument');
define('MERGED_INSTRUMENT', 'merged-instrument');
define('DATATABLE_PAGE', 'datatable-page');
define('CLOSEST', 'closest');
define('FIELD', 'field');
define('LIMITER', 'limiter');
define('PRIMARY_FIELDS', 'primary_fields');
define('SECONDARY_FIELDS', 'secondary_fields');
define('ON', 'on');
define('OFF', 'off');
define('REPEATING_UTILITY', 'repeating_utility');
define('ROWS_PER_CALL', 1000);
/**
 * this to save date field which will be used to filter data for secondary instruments.
 */
define('DATE_IDENTIFIER', 'date_identifier');

/**
 * Class ExportRepeatingData
 * @package Stanford\ExportRepeatingData
 * @property \Stanford\ExportRepeatingData\InstrumentMetadata $instrumentMetadata
 */
class ExportRepeatingData extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $project;

    private $instrumentMetadata;

    private $dataDictionary = array();



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
                $this->setDataDictionary(REDCap::getDataDictionary($this->getProject()->project_id, 'array'));

                $temp = json_decode($this->getProjectSetting("dates_identifiers"), true);

                $this->instrumentMetadata = new InstrumentMetadata($this->getProject()->project_id, $this->getDataDictionary());


            }

        } catch (\Exception $e) {
            echo $e->getMessage();
        }

    }

    public function initMeta() {
        $this->instrumentMetadata->init();
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
        $this->emLog('HELLO');
        error_log('HELLO error');
        return $this->getProject()->isRepeatingForm($this->getEventId(), $key);
    }

    public function sanitizeInputs($type = array())
    {
        if (empty($type)) {
            $type = $_POST;
        }
        foreach ($type as $key => $input) {
            $type[$key] = preg_replace('/[^a-zA-Z0-9\_\=\>\>=\<\<=](.*)$/', '', $type[$key]);
        }
    }

}
