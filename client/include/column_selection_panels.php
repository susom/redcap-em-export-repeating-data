<?php
// this php script generates HTML panels in the column selector widget
// initially all checkboxes are off and the panel is not visible
// when you drop an instrument or field from the left hand side the
// panel becomes visible and suitable checkboxes are selected

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
use \REDCap;
// something is taking a while to load. is this it?
// start debug setup part 1
// microtime(true) returns the unix timestamp plus milliseconds as a float
$starttime = microtime(true);
$module->emDebug("column_selection_panels launching");
// end debug setup part 1

$dataDict = REDCap::getDataDictionary('array');
$instruments = REDCap::getInstrumentNames();
//$event = $module->getFirstEventId();
?>

<?php
$primaryTagged = FALSE;
foreach ($instruments as $key => $instrument) {
    if ($module->isInstanceSelectLinked($key) == 1) {
        $cardinality = "tier-3";
        $tag = "<span class='badge badge-primary ml-5'>Repeating; related to " . $module->instanceSelectLink($key)  . "</span>";
    } else if ($module->isRepeatingForm($key) == 1 ) {
        if ($primaryTagged) {
            $cardinality = "tier-4";
            $tag = "<select name='<?php echo ?>' class='' style=';'>" .
            "<option value='0'>Repeating: Primary</option><option value='1' selected>Repeating: Secondary</option>" .
            "</select> if within <input style='width:30px' type='text' maxlength='4'/> before and <input style='width:30px'  type='text'/> after (days)";
        } else {
            $primaryTagged = TRUE;
            $cardinality = "tier-2";
            $tag = "<span class='badge badge-info ml-5'>Repeating: Primary/Anchor</span>";
        }
    } else {
        $cardinality = "tier-1";
        $tag = "<span class='badge badge-success ml-5'>Singleton</span>";
    }


    ?>
    <div style="display: none;" class=" ui-sortable-handle col-md-12 panel panel-default" id="panel-<?php echo $key ?>">
        <div class="panel-heading <?php echo $cardinality?>">
            <label for="chb1" class="pr-1"><?php echo $instrument ?> </label><input type="checkbox"  id="<?php echo $key ?>"  >
            <?php echo $tag ?>
            <button type="button" class="delete-panel close pr-2" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="panel-body">
            <div class="row">
                <?php
                $fields = REDCap::getFieldNames($key);
                foreach ($fields as $field) {
                    if ($dataDict[$field]['field_type'] === 'descriptive') {
                        continue;
                    }
                    ?>

                    <div class="col-md-3 cbox-panel">
                        <label for="chb2" class="pr-1"><?php echo $field ?> </label><input type="checkbox"  id="<?php echo $field ?>"  class="column-selector <?php echo $key ?>" >
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


