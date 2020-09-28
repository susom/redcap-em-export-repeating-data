<?php
// this php script generates HTML panels in the column selector widget
// initially all checkboxes are off and the panel is not visible
// when you drop an instrument or field from the left hand side the
// panel becomes visible and suitable checkboxes are selected

namespace Stanford\ExportRepeatingData;

/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */
use \REDCap;


$dataDict = REDCap::getDataDictionary('array');
$instruments = REDCap::getInstrumentNames();
//$event = $module->getFirstEventId();
?>

<?php
foreach ($instruments as $key => $instrument) {
    ?>
    <div style="display: none;" class=" ui-sortable-handle col-md-12 panel panel-default" id="panel-<?php echo $key ?>">
        <div class="panel-heading">
            <label for="chb1" class="pr-1"><?php echo $instrument ?> </label><input type="checkbox"  id="<?php echo $key ?>"  >
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

                    <div class="col-md-3">
                        <label for="chb2" class="pr-1"><?php echo $field ?> </label><input type="checkbox"  id="<?php echo $field ?>"  class="<?php echo $key ?>" >
                    </div>
                    <?php
                }
                ?>

            </div>
        </div>
    </div>
    <?php
}
?>



