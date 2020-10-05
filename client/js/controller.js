$(function () {
    // drag n drop file upload for UI settings restore from save file
    $('#holder').on({
        'dragover dragenter': function(e) {
            e.preventDefault();
            e.stopPropagation();
        },
        'drop': function(e) {
            //console.log(e.originalEvent instanceof DragEvent);
            var dataTransfer =  e.originalEvent.dataTransfer;
            if( dataTransfer && dataTransfer.files.length) {
                e.preventDefault();
                e.stopPropagation();
                $.each( dataTransfer.files, function(i, file) {
                    var reader = new FileReader();
                    reader.onload = $.proxy(function(file, $fileList, event) {
                        try {
                            clearError();
                            applyModel(JSON.parse(reader.result));
                        } catch(e) {
                            console.log(e);
                            showError("Unrecognized file type. To restore settings, select a file previously saved by clicking 'Save Settings'");
                        }
                    }, this, file, $("#fileList"));
                    reader.readAsText(file);

                });
            }
            $("#dialog").dialog('close');
        }
    });
});

function applyModel(model) {
    if( !model.hasOwnProperty('reportname')) {
        showError("Unrecognized file type. To restore settings, please select a file previously saved by clicking 'Save Settings'");
        return;
    }
    $("#report_name").val(model.reportname)
    values = model['columns'];
    if (values.length > 0) {
        $( "#tip_missing_col_1" ).remove();
        $( "#tip_missing_col_2" ).remove();

    }
    for (var i = 0; i < values.length; i++) {
        $("#panel-" + values[i].instrument).show();
        $("#" + values[i].instrument).prop("checked", true);
        $("#" + values[i].field).prop("checked", true);
    }
    tagRepeatables();
    values = model['filters'];
    if (values.length > 0) {
        $( "#tip_exporting_all_rows" ).remove();
    }
    for ( i=0; i < values.length; i++) {
        console.log(values[i]);
    }
}

function applyValdtn(uiElement) {
    // seems to be required by REDCap in the filter fields
    return true;
}

function runQuery(preview) {
    var formdata = $("#export-repeating").serializeArray();

    if (configurationError(formdata)) {
        return;
    }

    var json = getExportJson(preview, formdata);
    clearError();
    if (json.columns.length === 0) {
        showError("You must drag at least one field from the list on the left and drop it into the 'Specify Report Columns' box above. ");
        return;
    }
    $("#longop-running").show();
    $.ajax({
        url: $("#report-submit").val(),
        data: json,
        timeout: 60000000,
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            $("#longop-running").hide();
            if (response.status === 0) {
                showError("Error: " + response.message);
            } else {
                if (preview) {
                    var data = tableize(response.headers, response.data);
                    //console.log(data);
                    $("#preview-table-div").replaceWith(data);
                    $('#preview-table').DataTable();
                    $("#datatable").show();
                } else {
                    var data = convertToCSV(response.data);
                    triggerDownload(data, json.reportname + ".csv", 'text/csv;charset=utf-8;' )
                }
            }

        },
        error: function (request, error) {
            $("#longop-running").hide();
            showError("Server Error: " + JSON.stringify(error));
        }
    });
}

function promptForUpload() {
    $( "#dialog" ).dialog({
        resizable: false,
        height: "auto",
        width: 430,
        modal: true
    });
    $( "#dialog" ).show();
}

function configurationError(formdata) {

    var errorFound = false;
    formdata.forEach(function (item, index) {
        if ( (item.name.startsWith('lower-bound') || item.name.startsWith('upper-bound') ) ) {
            if ( isNaN(item.value) === true) {
                showError("non-numeric characters found in input string '" + item.value);
                errorFound = true;
            }
        }
    });

    if ( $(".badge-danger:visible").length > 0) {
        showError("Please ask your REDCap administrator to configure this project with @PRINCIPAL_DATE " +
            "and/or @FORMINSTANCE action tags as per the <a href='https://github.com/susom/redcap-em-export-repeating-data'>documentation for this module</a>");
        errorFound = true;
    }
    return errorFound;
}

function clearError() {
    $( "#data-error" ).hide();
}

function showError(message) {
    $( "#datatable" ).hide();
    $("#data-error-message").replaceWith('<div id="data-error-message"  class="alert alert-danger mt-5" >' + message + '</div>');
    $( "#data-error" ).show();
}

function tableize(headers, rows) {
    // datatable.net seems to expect full on <table><tr><td></td></tr></table> markup
    var table = '<div id="preview-table-div"><table id="preview-table" class="display"><thead>';
    headers.forEach(function (header, index) {table +=  '<th>' + header + '</th>';});
    table += '</thead><tbody>'
    rows.forEach(function (row, index) {
        table += '<tr>'
        row.forEach(function(cell, index) {
            table +=  '<td>' + cell + '</td>';
        });
        table += '</tr>'
    });
    table += '</tbody></table></div>'
    return table;
}

function tableize_col(col, index) {
    return '<td>' + col + '</td>';
}

function saveExportJson() {
    var formdata = $("#export-repeating").serializeArray();
    var json = getExportJson(false, formdata);
    triggerDownload(JSON.stringify(json), json.reportname + ".json", 'text/json;charset=utf-8;' )
}

function getExportJson(is_preview, formdata) {
    var struct = {};
    struct.reportname = 'unnamed_report';
    var columns =[];
    $('.column-selector').each(function() {
        if ($( this ).prop( 'checked' )) {
            var table = {};
            table.instrument = $(this).closest('.panel').attr('id').substr(6);
            table.field = $(this).prop('id');
            columns.push(table);
        }
    });

    // ok, now that we have the column specification, build up the filters.
    // the inputs generated by the REDCap API call have a quirky naming convention
    // process the results in the order returned, looking for these four names:
    // field_name, limiter_operator[], limiter_value[], and limiter_connector[]
    // we get back an array of objects with name: and value: as the two properties
    // this is the target format:
    //        "filters": [{
    //                 "instrument": "person",
    //                 "field": "record_id",
    //                 "operator": "equals",
    //                 "param": "1",
    //                 "boolean": "AND"
    //             }

    var filters = [];
    var filter;
    var joins = [];
    var join;
    var cardinality = [];

    formdata.forEach(function (item, index) {

        if (item.name === 'field_name') {
            filter = {};
            filter.field = item.value;
        } else if (item.name === 'limiter_operator[]') {
            filter.operator = item.value;
        } else if (item.name === 'limiter_value[]') {
            filter.param = item.value;
        } else if (item.name === 'limiter_connector[]') {
            filter.boolean = item.value;
            console.log('looking up instrument for');
            console.log(filter.field);
            console.log(getInstrumentForField(filter.field));
            filter.instrument = getInstrumentForField(filter.field);
            filters.push(Object.assign({}, filter));
        } else if (item.name === 'report_name') {
            if (item.value.length > 0) {
                struct.reportname = item.value;
            }
        } else if (item.name.startsWith('lower-bound')) {
            join = {};
            join.instrument_name = item.name.substr(12);
            join.lower_bound = item.value;
        } else if (item.name.startsWith('upper-bound')) {
            join.upper_bound = item.value;
            joins[join.instrument_name] = (Object.assign({}, join));
        }
    });

    // assemble the list of visible panels, in order, with their status
    $(".panel:visible").each(function() {
        join = {};
        instrument_name= $(this).attr('id').substr(6);

        var panelHeading = $(this).find(".panel-heading");
        if (panelHeading.hasClass('tier-0')) {
            join.join = 'singleton'
        } else if (panelHeading.hasClass('tier-1')) {
            join.join = 'repeating-primary'
        } else if (panelHeading.hasClass('tier-2')) {
            join.join = 'repeating-instance-select'
        } else if (panelHeading.hasClass('tier-3')) {
            join.join = 'repeating-date-pivot'
        }

        cardinality[instrument_name] = Object.assign({}, join);
        if (join.join !== 'singleton') {
            cardinality[instrument_name].upper_bound = joins[instrument_name].upper_bound;
            cardinality[instrument_name].lower_bound = joins[instrument_name].lower_bound;
        }
    });


    struct.preview = is_preview ;
    struct.project = "standard";
    struct.columns = columns;
    struct.filters = filters;
    struct.cardinality = Object.assign({}, cardinality);
    console.log(struct);
    return struct;

}

function convertToCSV(objArray) {
    var array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
    var str = '';

    for (var i = 0; i < array.length; i++) {
        var line = '';
        for (var index in array[i]) {
            if (line != '') line += ','
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
            line += escape_doublquotes(array[i][index]);
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
        }

        str += line + '\r\n';
    }

    return str;
}

function escape_doublquotes(data) {
    if (!data) return data;
    return data.replace(/["]/g, '""');
}

function triggerDownload(data, filename, filetype) {
    console.log('stringified version');
    console.log(data);
    var blob = new Blob([ data ], {type: filetype});
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename );
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) { // feature detection
            // Browsers that support HTML5 download attribute
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename );
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}

