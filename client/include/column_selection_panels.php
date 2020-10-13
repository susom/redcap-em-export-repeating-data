<?php
// this php script generates HTML panels in the column selector widget
// initially all checkboxes are off and the panel is not visible
// when you drop an instrument or field from the left hand side the
// panel becomes visible and suitable checkboxes are selected

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */

// start debug setup part 1
// microtime(true) returns the unix timestamp plus milliseconds as a float
$starttime = microtime(true);
$module->emDebug("column_selection_panels launching");
// end debug setup part 1

$dataDict = $module->getDataDictionary();
$instruments = $module->getInstrumentNames();
//$event = $module->getFirstEventId();
?>

<?php

$primaryTag = "<span class='badge badge-info ml-5 repeating-primary'>Repeating: Primary/Anchor</span>";
foreach ($instruments as $key => $instrument) {
    if ($module->isRepeatingForm($key) == 1 ) {
        $mydate = $module->getDateField($key);
        if ( $module->instanceSelectLink($key)   || $module->hasChild($key)) {
            $secondaryTag = "<span class='badge badge-primary ml-5 repeating-secondary'>Repeating; related to "
                . ($module->instanceSelectLink($key) ? $module->instanceSelectLink($key) : $module->hasChild($key)) . "</span>";
        } else {
            $secondaryTag = "";
        }
        $tertiaryTag = "<span class='badge badge-warning ml-5 repeating-tertiary'>Repeating; Pivot & Filter</span><div class='repeating-tertiary'> closest " . $mydate . " within <input id='lower-bound-".$key."' name='lower-bound-".$key."' style='width:30px' type='text' maxlength='4'/> before and <input id='upper-bound-".$key."' name='upper-bound-".$key."' style='width:30px'  type='text'/> <span class='target-date'> after @targetdate@ (days)</span></div>";
        // these are the static defaults; they get rewritten dynamically
        if (! $mydate && $module->isInstanceSelectLinked($key) == 0) {
            $cardinality = "tier-error";
            $tag = "<span class='badge badge-danger ml-5'>Configuration Error</span>";
        } else {
            $cardinality = "tier-2";
            $tag = $primaryTag . $secondaryTag . $tertiaryTag;
        }
    } else {
        $cardinality = "tier-0";
        $tag = "<span class='badge badge-success ml-5'>Singleton</span>";
    }
    // cardinality is used to set the panel heading background color
    // tier-0 is easy, it's always green
    // tier-1 however is situational
    // tier-1 is dynamically applied to the first repeating form in the list
    // however if you are not the first repeating form in the list, your native coloring is applied
    // this native coloring is stashed below for future reference as class ref-tier-2 or ref-tier-3
    // see drag_n_drop.php for the dynamic behaviors in javascript

    ?>
    <div style="display: none;" class="ui-sortable-handle col-md-12 panel panel-default" id="panel-<?php echo $key ?>">
        <div class="panel-heading <?php echo $cardinality?> ref-<?php echo $cardinality?>">
            <label for="chb1" class="pr-1"><?php echo $instrument ?> <input type="checkbox"  id="<?php echo $key ?>_cb"  ></label>
            <?php echo $tag ?>
            <button type="button" class="delete-panel close pr-2" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="panel-body">
            <div class="row">
                <?php
                $fields = $module->getFieldNames($key);
                foreach ($fields as $field) {
                    if ($dataDict[$field]['element_type'] === 'descriptive') {
                        continue;
                    }
                    ?>

                    <div class="col-md-3 cbox-panel">
                        <label for="chb2" class="pr-1"><?php echo $field ?> <input type="checkbox"  id="<?php echo $field ?>"  class="column-selector <?php echo $key ?>" ></label>
                    </div>
                    <?php
                }
                ?>

            </div>
        </div>
    </div>
    <?php
}

// start debug setup part 2
$endtime = microtime(true);
$timediff = $endtime - $starttime;
$module->emDebug("column_selection_panels completed in " . $module->secondsToTime($timediff));
// end debug setup part 2

?>


