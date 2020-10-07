<?php

namespace Stanford\ExportRepeatingData;

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
    private $instrumentFields;
    private $instrumentNames;

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

    private function initInstrumentFields() {
        $this->instrumentFields = [];
        foreach ($this->dataDictionary as $key => $ddEntry) {
            if (!isset ($this->instrumentFields[$ddEntry['form_name'] . "_fields"])) {
                $this->instrumentFields[$ddEntry['form_name'] . "_fields"] = [];
            }
            $this->instrumentFields[$ddEntry['form_name'] . "_fields"][] = $key;
        }
    }

    private function initInstrumentNames() {
        $this->instrumentNames = [];
        foreach ($this->dataDictionary as $key => $ddEntry) {
            if (! isset($this->instrumentNames[$ddEntry['form_name']] )) {
                $this->instrumentNames[$ddEntry['form_name']] = $ddEntry['form_name'];
            }
        }
    }

    /**
     * @return array
     */
    public function getInstrumentNames() {
        if (! isset($this->instrumentNames)) {
            $this->initInstrumentNames();
        }
        return $this->instrumentNames;
    }

    /**
     * @return array
     */
    public function getFieldNames($instrument) {
        if (! isset($this->instrumentFields)) {
            $this->initInstrumentFields();
        }
        return $this->instrumentFields[$instrument . "_fields"];
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
    public function getDateField($instrument)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }
        return $this->resultArray[$instrument]['principal_date'];
    }

    /**
     *
     */
    public function getValidation($key)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }
        return $this->resultArray[$key];
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
    public function hasChild($instrument)
    {
        if (! isset($this->resultArray)) {
            $this->init();
        }

        foreach ($this->resultArray as $visiblekey => $visibleval) {
                if ($this->resultArray[$visiblekey]['foreign_key_ref'] === $instrument) {
                    $foundParent = $visiblekey;
                    break;
            }
        }
        return  $foundParent;
    }

    /**
     *
     */
    private function init()
    {
        global $module;
        // look up whether this is a longitudinal or standard project
        $sql = "select count(1) as cnt from redcap_events_arms where project_id= " . db_escape($this->pid);

        $result = db_query($sql);

        foreach ($result as $record) {
            $this->isStandard = ($record['cnt'] == 1);
        }
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
            $lookupTable[$record['instrument']] = $record;
        }

        // now look in the data dictionary for action tags indicating foreign key relationships
        foreach ($this->dataDictionary as $key => $ddEntry) {

            if (contains($ddEntry['misc'],'@FORMINSTANCE')) {
                $parent_instrument = $this->valueOfActionTag('FORMINSTANCE',  $ddEntry['misc']);
                $lookupTable[$ddEntry['form_name']]['foreign_key_ref'] = $parent_instrument;
                $lookupTable[$ddEntry['form_name']]['foreign_key_field'] = $ddEntry['field_name'];
                // add one more entry, indicating that the parent is linked to the child

                $lookupTable[$parent_instrument]['children'][] = $ddEntry['form_name'];

            } else if (!isset($lookupTable[$ddEntry['form_name']]['foreign_key_ref'])) {
                $lookupTable[$ddEntry['form_name']]['foreign_key_ref'] = '';
                $lookupTable[$ddEntry['form_name']]['foreign_key_field'] = '';
            }

            // make a note of the fields tagged as @PRINCIPAL_DATE for later use when displaying the secondary table join options
            if (contains($ddEntry['misc'],'@PRINCIPAL_DATE')) {
                $lookupTable[$ddEntry['form_name']]['principal_date']  = $ddEntry['field_name'];
            }
            // last but not least, stash a local copy of the validation string
            $lookupTable[$ddEntry['field_name']]  = $ddEntry['element_validation_type'];

        }

        $this->resultArray = $lookupTable;
    }

    private function valueOfActionTag($actionTag, $allTags) {
        $annotation = $allTags;

        // there are multiple action tags associated with a given form field
        $elements = explode('@', $allTags );
        foreach ($elements as $element) {
            if (contains($element,$actionTag)) {
                $annotation = $element;
            }
        }
        // pick out the value from this action tag
        $components = explode('=', $annotation );
        // $components[0] is the action tag, and $components[1] is the value we want
        return $components[1];
    }

} ?>

