<?php

namespace Stanford\ExportRepeatingData;

use \REDCap;
use \Project;
define('STANDARD', 'standard');
define('LONGITUDINAL', 'longitudinal');

/**
 * Class InstrumentMetadata
 * This utility class caches metadata for all instruments associated with the project
 * and is able to assign values for a given form on the attributes
 * <li> cardinality: whether a given form is a singleton or repeating
 * <li> foreign keys: which field refers to a parent instrument
 * @package Stanford\ExportRepeatingData
 *
 */
class InstrumentMetadata
{
    private $Proj;
    private $pid;
    private $dataDictionary;
    private $resultArray;
    private $isStandard;

    function __construct($pid, $dataDictionary)
    {
        try {
            global $Proj;

            if ($Proj->project_id == $pid) {
                $this->Proj = $Proj;
            } else {
                $this->Proj = new Project($pid);
            }

            $this->dataDictionary = $dataDictionary;

            if (empty($this->Proj) or ($this->Proj->project_id != $pid)) {
                $this->last_error_message = "Cannot determine project ID in InstrumentMetadata";
            }
            $this->pid = $pid;

        } catch (\Exception $e) {
            echo $e->getMessage();
            die();
        }
    }

    /**
     *
     */
    public function isRepeating($instrument)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }
        return $this->resultArray[$instrument];
    }

    /**
     *
     */
    public function isInstanceSelectLinked($instrument)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }
        return( isset( $this->resultArray[$instrument]['foreign_key_ref'])
            && strlen($this->resultArray[$instrument]['foreign_key_ref']) > 0);

    }

    /**
     *
     */
    public function instanceSelectLinked($instrument)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }
        return  $this->resultArray[$instrument]['foreign_key_ref'];
    }

    /**
     *
     */
    private function init()
    {
        // look up whether this is a longitudinal or standard project
        $sql = "select count(1) as cnt from redcap_events_arms where project_id= " . db_escape($this->pid);

        $result = db_query($sql);
        error_log('longitudinal?');
        foreach ($result as $record) {
            error_log(print_r($record, TRUE));
            $this->isStandard = ($record['cnt'] == 1);
        }
    //    error_log($this->isStandard);
        // now build the list of attributes for all instruments associated with the project
        $sql = "select distinct md.form_name as instrument,
           case when rer.form_name is not null then 'repeating' else 'singleton' end as cardinality
           from redcap_metadata md
             join redcap_data rd on md.project_id = rd.project_id and md.field_name = rd.field_name
             left outer join redcap_events_repeat rer on rer.event_id = rd.event_id and rer.form_name = md.form_name
           where md.project_id = " . db_escape($this->pid);
        // create a temporary hash table to make it easier to augment the data structure
        $lookupTable = array();
        $result = db_query($sql);
        foreach ($result as $record) {
            $resultArray[] = $record;
            $lookupTable[$record['instrument']] = $record;
        }

        // now look in the data dictionary for action tags indicating foreign key relationships
        foreach ($this->dataDictionary as $key => $ddEntry) {
            if (contains($ddEntry['field_annotation'],'@FORMINSTANCE')) {
                $annotation = $ddEntry['field_annotation'];

                    // there are multiple action tags associated with a given form field
                    $elements = explode(' ', $ddEntry['field_annotation'] );
                    foreach ($elements as $element) {
                        if (contains($element,'@FORMINSTANCE')) {
                            $annotation = $element;
                        }
                    }
                // pick out the value from this action tag
                $components = explode('=', $annotation );
                // $components[0] is the action tag, and $components[1] is the value we want
                $lookupTable[$ddEntry['form_name']]['foreign_key_ref'] = $components[1];
                $lookupTable[$ddEntry['form_name']]['foreign_key_field'] = $ddEntry['field_name'];

            } else if (!isset($lookupTable[$ddEntry['form_name']]['foreign_key_ref'])) {
                $lookupTable[$ddEntry['form_name']]['foreign_key_ref'] = '';
                $lookupTable[$ddEntry['form_name']]['foreign_key_field'] = '';
            }
        }
        $this->resultArray = $lookupTable;
      //  error_log(print_r($this->resultArray,TRUE));
    }

} ?>

