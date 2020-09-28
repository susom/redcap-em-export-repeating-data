### Client Server Architecture
This EM has two distinct components. The client main entry point is index.php;
the main helper class is ExportRepeatingData.php. Various client-side supporting scripts,
libaries and ancillary helper classes are all under the client subdirectory.

The client's job is to allow the user to create a data extraction specification, 
represented in JSON. This JSON is passed to the server when the user clicks either
the Preview or Export buttons; the server then converts the specification to SQL
which is used to actually retrieve the data.

The top level components of the specification are
1. report columns specified as instrument/field name pairs
1. data cardinality and foreign key relationships 
1. optional row filters

The report column specification is used to generate a set of SELECT - FROM statements. 
Row filters, when supplied, are translated to WHERE clauses. 
Table joins are then added using the data cardinality and foreign key relationship information.

#### JSON object
The Json object has four attributes: project, columns, cardinality and filters. 
The value of project is either standard or longitudinal; the other three attributes
are lists.

The columns list contains objects with attributes instrument, field and is_date.

The cardinality list contains objects with attributes instrument, cardinality,
foreign_key_field and foreign_key_ref.

The filters list contains objects with attributes instrument, field, operator,
param1 and param2. 

#### JSON example
```javascript
{
    "project": "standard",
    "columns": [{
            "instrument": "person",
            "field": "last_name",
            "is_date": false
        },
        {
            "instrument": "person",
            "field": "first_name",
            "is_date": false
        },
        {
            "instrument": "visit",
            "field": "visit_date",
            "is_date": true
        },
        {
            "instrument": "meds",
            "field": "medication",
            "is_date": false
        }
    ],
    "cardinality": [{
            "instrument": "person",
            "cardinality": "singleton"
            "foreign_key_field": "",
            "foreign_key_ref": ""
        },
        {
            "instrument": "visit",
            "cardinality": "repeating",
            "foreign_key_field": "",
            "foreign_key_ref": ""
        },
        {
            "instrument": "meds",
            "cardinality": "repeating",
            "foreign_key_field": "med_visit_instance",
            "foreign_key_ref": "visit"
        }
    ],
    "filters": [{
            "instrument": "person",
            "field": "record_id",
            "operator": "equals",
            "param1": "1",
            "param2": ""
        }
    ]
}

```
