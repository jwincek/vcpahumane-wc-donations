/**
 * Import/Export Admin UI
 * 
 * No-build WordPress components UI using wp.element and wp.components.
 * 
 * @package Starter_Shelter
 * @since 1.0.0
 */

( function() {
    'use strict';

    // Ensure WordPress dependencies are available
    if ( typeof wp === 'undefined' || ! wp.element || ! wp.components ) {
        console.error( 'Starter Shelter: Required WordPress scripts not loaded.' );
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var Fragment = wp.element.Fragment;
    var render = wp.element.render;
    
    var Button = wp.components.Button;
    var Card = wp.components.Card;
    var CardBody = wp.components.CardBody;
    var CardHeader = wp.components.CardHeader;
    var CardFooter = wp.components.CardFooter;
    var Flex = wp.components.Flex;
    var FlexItem = wp.components.FlexItem;
    var SelectControl = wp.components.SelectControl;
    var CheckboxControl = wp.components.CheckboxControl;
    var Notice = wp.components.Notice;
    var Spinner = wp.components.Spinner;
    var TabPanel = wp.components.TabPanel;
    var Icon = wp.components.Icon;
    var DropZone = wp.components.DropZone;
    var FormFileUpload = wp.components.FormFileUpload;

    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;

    /**
     * Export Card Component
     */
    function ExportCard( props ) {
        var icon = props.icon;
        var title = props.title;
        var description = props.description;
        var count = props.count;
        var options = props.options;
        var onExport = props.onExport;
        var isExporting = props.isExporting;

        var optionState = useState( options && options.length > 0 ? options[0].value : 'all' );
        var selectedOption = optionState[0];
        var setSelectedOption = optionState[1];

        return el( Card, { className: 'sd-export-card' },
            el( CardHeader, {},
                el( Flex, { justify: 'space-between', align: 'flex-start' },
                    el( FlexItem, {},
                        el( 'span', { style: { fontSize: '32px' } }, icon )
                    ),
                    el( FlexItem, { style: { textAlign: 'right' } },
                        el( 'div', { style: { fontSize: '24px', fontWeight: '600' } }, 
                            count.toLocaleString() 
                        ),
                        el( 'div', { style: { fontSize: '11px', color: '#757575', textTransform: 'uppercase' } }, 
                            __( 'records', 'starter-shelter' ) 
                        )
                    )
                )
            ),
            el( CardBody, {},
                el( 'h3', { style: { marginTop: 0, marginBottom: '8px', fontSize: '16px' } }, title ),
                el( 'p', { style: { color: '#757575', marginBottom: '16px' } }, description ),
                options && options.length > 0 ? el( SelectControl, {
                    value: selectedOption,
                    options: options.map( function( opt ) {
                        return {
                            value: opt.value,
                            label: opt.count !== undefined 
                                ? opt.label + ' (' + opt.count.toLocaleString() + ')'
                                : opt.label
                        };
                    }),
                    onChange: setSelectedOption,
                    __nextHasNoMarginBottom: true
                }) : null
            ),
            el( CardFooter, {},
                el( Button, {
                    variant: 'primary',
                    disabled: count === 0 || isExporting,
                    isBusy: isExporting,
                    onClick: function() { onExport( selectedOption ); }
                }, __( 'Export CSV', 'starter-shelter' ) )
            )
        );
    }

    /**
     * File Upload Area Component
     */
    function FileUploadArea( props ) {
        var onFileSelect = props.onFileSelect;
        var file = props.file;

        var dragState = useState( false );
        var isDragging = dragState[0];
        var setIsDragging = dragState[1];

        function handleDrop( files ) {
            if ( files && files.length > 0 ) {
                onFileSelect( files[0] );
            }
        }

        var dropZoneStyle = {
            border: '2px dashed ' + ( isDragging ? '#007cba' : '#c3c4c7' ),
            borderRadius: '4px',
            padding: '32px',
            textAlign: 'center',
            backgroundColor: isDragging ? '#f0f6fc' : '#f6f7f7',
            transition: 'all 0.2s ease',
            position: 'relative',
            minHeight: '100px'
        };

        if ( file ) {
            return el( 'div', { style: dropZoneStyle },
                el( 'div', { style: { fontSize: '32px', marginBottom: '8px' } }, '📄' ),
                el( 'p', { style: { margin: '0 0 4px', fontWeight: '500' } }, file.name ),
                el( 'p', { style: { margin: '0 0 8px', fontSize: '12px', color: '#757575' } }, 
                    ( file.size / 1024 ).toFixed( 1 ) + ' KB'
                ),
                el( Button, {
                    variant: 'link',
                    isDestructive: true,
                    onClick: function() { onFileSelect( null ); }
                }, __( 'Remove', 'starter-shelter' ) )
            );
        }

        return el( 'div', { 
            style: dropZoneStyle,
            onDragEnter: function() { setIsDragging( true ); },
            onDragLeave: function() { setIsDragging( false ); },
            onDrop: function() { setIsDragging( false ); }
        },
            el( DropZone, { onFilesDrop: handleDrop } ),
            el( 'div', { style: { fontSize: '32px', marginBottom: '8px' } }, '📤' ),
            el( 'p', { style: { margin: '0 0 8px' } }, 
                __( 'Drag and drop a CSV file here', 'starter-shelter' ) 
            ),
            el( FormFileUpload, {
                accept: '.csv',
                onChange: function( e ) { 
                    if ( e.target.files && e.target.files[0] ) {
                        onFileSelect( e.target.files[0] ); 
                    }
                }
            }, 
                el( Button, { variant: 'secondary' }, __( 'Select File', 'starter-shelter' ) )
            )
        );
    }

    /**
     * Preview Table Component
     */
    function PreviewTable( props ) {
        var headers = props.headers;
        var rows = props.rows;

        if ( ! rows || rows.length === 0 ) {
            return null;
        }

        var displayHeaders = headers.slice( 0, 5 );

        return el( 'div', { style: { overflowX: 'auto', marginTop: '16px' } },
            el( 'table', { 
                className: 'widefat',
                style: { fontSize: '13px' }
            },
                el( 'thead', {},
                    el( 'tr', {},
                        [ el( 'th', { key: 'num' }, '#' ) ].concat(
                            displayHeaders.map( function( header, i ) {
                                return el( 'th', { key: 'h' + i }, header );
                            })
                        ).concat( [ el( 'th', { key: 'status' }, __( 'Status', 'starter-shelter' ) ) ] )
                    )
                ),
                el( 'tbody', {},
                    rows.slice( 0, 5 ).map( function( row, i ) {
                        return el( 'tr', { 
                            key: 'r' + i,
                            style: { backgroundColor: row.valid ? '#edfaef' : '#fcf0f1' }
                        },
                            [ el( 'td', { key: 'n' }, i + 1 ) ].concat(
                                displayHeaders.map( function( header, j ) {
                                    var value = row.data && row.data[ header ] ? String( row.data[ header ] ) : '';
                                    return el( 'td', { key: 'd' + j }, value.substring( 0, 25 ) );
                                })
                            ).concat( [
                                el( 'td', { key: 's' },
                                    row.valid 
                                        ? el( 'span', { style: { color: '#00a32a' } }, '✓' )
                                        : el( 'span', { style: { color: '#d63638' } }, row.error || 'Error' )
                                )
                            ] )
                        );
                    })
                )
            ),
            rows.length > 5 ? el( 'p', { style: { color: '#757575', marginTop: '8px', fontSize: '13px' } },
                sprintf( __( '... and %d more rows', 'starter-shelter' ), rows.length - 5 )
            ) : null
        );
    }

    /**
     * Import Card Component
     */
    function ImportCard( props ) {
        var icon = props.icon;
        var title = props.title;
        var description = props.description;
        var requiredColumns = props.requiredColumns;
        var optionalColumns = props.optionalColumns;
        var templateUrl = props.templateUrl;
        var importAction = props.importAction;
        var options = props.options;

        var fileState = useState( null );
        var file = fileState[0];
        var setFile = fileState[1];

        var previewState = useState( null );
        var preview = previewState[0];
        var setPreview = previewState[1];

        var loadingState = useState( false );
        var isLoading = loadingState[0];
        var setIsLoading = loadingState[1];

        var importingState = useState( false );
        var isImporting = importingState[0];
        var setIsImporting = importingState[1];

        var resultsState = useState( null );
        var results = resultsState[0];
        var setResults = resultsState[1];

        // Initialize options state
        var defaultOptions = {};
        if ( options ) {
            options.forEach( function( opt ) {
                defaultOptions[ opt.key ] = opt.default;
            });
        }
        var optionsState = useState( defaultOptions );
        var importOptions = optionsState[0];
        var setImportOptions = optionsState[1];

        function handleFileSelect( selectedFile ) {
            setFile( selectedFile );
            setPreview( null );
            setResults( null );

            if ( selectedFile ) {
                setIsLoading( true );
                
                var formData = new FormData();
                formData.append( 'action', 'sd_preview_import' );
                formData.append( 'import_type', importAction );
                formData.append( 'file', selectedFile );
                formData.append( 'nonce', sdImportExport.previewNonce );

                fetch( sdImportExport.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then( function( response ) { return response.json(); } )
                .then( function( data ) {
                    if ( data.success && data.data ) {
                        setPreview( data.data );
                    }
                    setIsLoading( false );
                })
                .catch( function( error ) {
                    console.error( 'Preview error:', error );
                    setIsLoading( false );
                });
            }
        }

        function handleImport() {
            if ( ! file ) return;

            setIsImporting( true );

            var formData = new FormData();
            formData.append( 'action', 'sd_process_import_' + importAction );
            formData.append( 'file', file );
            formData.append( 'nonce', sdImportExport.importNonce );
            
            Object.keys( importOptions ).forEach( function( key ) {
                formData.append( key, importOptions[ key ] ? '1' : '0' );
            });

            fetch( sdImportExport.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then( function( response ) { return response.json(); } )
            .then( function( data ) {
                if ( data.success && data.data ) {
                    setResults( data.data );
                    setFile( null );
                    setPreview( null );
                }
                setIsImporting( false );
            })
            .catch( function( error ) {
                console.error( 'Import error:', error );
                setIsImporting( false );
            });
        }

        function handleOptionChange( key, value ) {
            var newOptions = Object.assign( {}, importOptions );
            newOptions[ key ] = value;
            setImportOptions( newOptions );
        }

        var validRows = preview && preview.validRows ? preview.validRows : 0;

        return el( Card, { className: 'sd-import-card' },
            el( CardHeader, {},
                el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
                    el( 'span', { style: { fontSize: '32px' } }, icon ),
                    el( 'div', {},
                        el( 'h3', { style: { margin: 0, fontSize: '16px' } }, title ),
                        el( 'p', { style: { margin: '4px 0 0', color: '#757575', fontSize: '13px' } }, description )
                    )
                )
            ),
            el( CardBody, {},
                // Results notice
                results ? el( Notice, {
                    status: results.errors > 0 ? 'warning' : 'success',
                    isDismissible: true,
                    onRemove: function() { setResults( null ); }
                },
                    el( 'strong', {}, __( 'Import Complete!', 'starter-shelter' ) + ' ' ),
                    results.created > 0 ? el( 'span', { style: { color: '#00a32a' } }, results.created + ' created ' ) : null,
                    results.updated > 0 ? el( 'span', { style: { color: '#0073aa' } }, results.updated + ' updated ' ) : null,
                    results.skipped > 0 ? el( 'span', { style: { color: '#996800' } }, results.skipped + ' skipped ' ) : null,
                    results.errors > 0 ? el( 'span', { style: { color: '#d63638' } }, results.errors + ' errors' ) : null
                ) : null,

                // Template info
                el( 'div', { 
                    style: { 
                        backgroundColor: '#f0f6fc', 
                        border: '1px solid #c3c4c7',
                        borderLeft: '4px solid #007cba',
                        padding: '12px 16px',
                        borderRadius: '0 4px 4px 0',
                        marginBottom: '16px',
                        fontSize: '13px'
                    }
                },
                    el( 'div', {},
                        el( 'strong', {}, __( 'Required: ', 'starter-shelter' ) ),
                        requiredColumns.join( ', ' )
                    ),
                    optionalColumns && optionalColumns.length > 0 ? el( 'div', { style: { marginTop: '4px' } },
                        el( 'strong', {}, __( 'Optional: ', 'starter-shelter' ) ),
                        optionalColumns.join( ', ' )
                    ) : null
                ),

                // File upload
                el( FileUploadArea, { 
                    onFileSelect: handleFileSelect,
                    file: file
                }),

                // Loading
                isLoading ? el( 'div', { style: { textAlign: 'center', padding: '16px' } },
                    el( Spinner ),
                    el( 'span', { style: { marginLeft: '8px' } }, __( 'Analyzing file...', 'starter-shelter' ) )
                ) : null,

                // Preview
                preview ? el( 'div', { style: { marginTop: '16px' } },
                    el( 'p', { style: { fontWeight: '500', marginBottom: '8px' } }, 
                        sprintf( 
                            __( 'Preview: %d rows found, %d valid', 'starter-shelter' ), 
                            preview.totalRows || 0, 
                            validRows
                        )
                    ),
                    preview.rows ? el( PreviewTable, {
                        headers: preview.headers || [],
                        rows: preview.rows || []
                    }) : null
                ) : null,

                // Options
                preview && validRows > 0 && options && options.length > 0 ? el( 'div', { style: { marginTop: '16px' } },
                    options.map( function( opt ) {
                        return el( CheckboxControl, {
                            key: opt.key,
                            label: opt.label,
                            checked: importOptions[ opt.key ],
                            onChange: function( val ) { handleOptionChange( opt.key, val ); },
                            __nextHasNoMarginBottom: true
                        });
                    })
                ) : null
            ),
            el( CardFooter, {},
                el( Flex, { justify: 'space-between' },
                    el( Button, {
                        variant: 'secondary',
                        href: templateUrl
                    }, __( 'Download Template', 'starter-shelter' ) ),
                    el( Button, {
                        variant: 'primary',
                        disabled: validRows === 0 || isImporting,
                        isBusy: isImporting,
                        onClick: handleImport
                    }, isImporting 
                        ? __( 'Importing...', 'starter-shelter' )
                        : sprintf( __( 'Import %d Rows', 'starter-shelter' ), validRows )
                    )
                )
            )
        );
    }

    /**
     * Legacy Memorial Import Component
     * 
     * Handles the shelter's legacy memorial CSV format:
     * - Column A: "In Memory Of" (honoree name)
     * - Column B: "By" (donor name)
     * - Column C: Optional "pet" indicator
     * - Month names as section headers
     */
    function LegacyMemorialImport() {
        var fileState = useState( null );
        var file = fileState[0];
        var setFile = fileState[1];

        var previewState = useState( null );
        var preview = previewState[0];
        var setPreview = previewState[1];

        var loadingState = useState( false );
        var isLoading = loadingState[0];
        var setIsLoading = loadingState[1];

        var importingState = useState( false );
        var isImporting = importingState[0];
        var setIsImporting = importingState[1];

        var resultsState = useState( null );
        var results = resultsState[0];
        var setResults = resultsState[1];

        var yearState = useState( new Date().getFullYear() );
        var year = yearState[0];
        var setYear = yearState[1];

        var skipDuplicatesState = useState( true );
        var skipDuplicates = skipDuplicatesState[0];
        var setSkipDuplicates = skipDuplicatesState[1];

        var defaultAmountState = useState( 0 );
        var defaultAmount = defaultAmountState[0];
        var setDefaultAmount = defaultAmountState[1];

        function handleFileSelect( selectedFile ) {
            setFile( selectedFile );
            setPreview( null );
            setResults( null );

            if ( selectedFile ) {
                setIsLoading( true );
                
                var formData = new FormData();
                formData.append( 'action', 'sd_preview_import_memorials_legacy' );
                formData.append( 'file', selectedFile );
                formData.append( 'year', year );
                formData.append( 'nonce', sdImportExport.importNonce );

                fetch( sdImportExport.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then( function( response ) { return response.json(); } )
                .then( function( data ) {
                    if ( data.success && data.data ) {
                        setPreview( data.data );
                    } else {
                        alert( data.data || 'Preview failed' );
                    }
                    setIsLoading( false );
                })
                .catch( function( error ) {
                    console.error( 'Preview error:', error );
                    setIsLoading( false );
                });
            }
        }

        function handleImport() {
            if ( ! file ) return;

            if ( ! confirm( sprintf( 
                __( 'Import %d memorials for year %d?', 'starter-shelter' ), 
                preview.total_rows, 
                year 
            ) ) ) {
                return;
            }

            setIsImporting( true );

            var formData = new FormData();
            formData.append( 'action', 'sd_process_import_memorials_legacy' );
            formData.append( 'file', file );
            formData.append( 'year', year );
            formData.append( 'skip_duplicates', skipDuplicates ? '1' : '0' );
            formData.append( 'default_amount', defaultAmount );
            formData.append( 'nonce', sdImportExport.importNonce );

            fetch( sdImportExport.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then( function( response ) { return response.json(); } )
            .then( function( data ) {
                if ( data.success ) {
                    setResults( data.data );
                    setFile( null );
                    setPreview( null );
                } else {
                    alert( data.data || 'Import failed' );
                }
                setIsImporting( false );
            })
            .catch( function( error ) {
                console.error( 'Import error:', error );
                alert( 'Import failed: ' + error.message );
                setIsImporting( false );
            });
        }

        // Generate year options (current year - 5 to current year)
        var currentYear = new Date().getFullYear();
        var yearOptions = [];
        for ( var y = currentYear; y >= currentYear - 5; y-- ) {
            yearOptions.push( { value: y.toString(), label: y.toString() } );
        }

        return el( Card, { className: 'sd-import-card' },
            el( CardHeader, {},
                el( Flex, { justify: 'space-between', align: 'center' },
                    el( FlexItem, {},
                        el( 'span', { style: { fontSize: '24px', marginRight: '8px' } }, '📋' ),
                        el( 'span', { style: { fontWeight: '600' } }, __( 'Legacy Memorial Import', 'starter-shelter' ) )
                    ),
                    el( FlexItem, {},
                        el( 'span', { 
                            style: { 
                                background: '#f0f6fc', 
                                color: '#0073aa', 
                                padding: '4px 8px', 
                                borderRadius: '4px',
                                fontSize: '11px',
                                fontWeight: '500'
                            } 
                        }, __( 'Shelter Format', 'starter-shelter' ) )
                    )
                )
            ),
            el( CardBody, {},
                el( 'p', { style: { color: '#757575', marginBottom: '16px' } },
                    __( 'Import memorials from the shelter\'s legacy CSV format (In Memory Of, By, pet columns with month headers).', 'starter-shelter' )
                ),

                // Settings
                el( 'div', { style: { marginBottom: '16px', display: 'flex', gap: '16px', flexWrap: 'wrap' } },
                    el( SelectControl, {
                        label: __( 'Year', 'starter-shelter' ),
                        value: year.toString(),
                        options: yearOptions,
                        onChange: function( val ) { setYear( parseInt( val, 10 ) ); },
                        style: { minWidth: '100px' }
                    }),
                    el( 'div', {},
                        el( 'label', { 
                            style: { display: 'block', marginBottom: '8px', fontWeight: '500' } 
                        }, __( 'Default Amount ($)', 'starter-shelter' ) ),
                        el( 'input', {
                            type: 'number',
                            min: '0',
                            step: '0.01',
                            value: defaultAmount,
                            onChange: function( e ) { setDefaultAmount( parseFloat( e.target.value ) || 0 ); },
                            style: { width: '100px', padding: '8px' }
                        })
                    )
                ),

                el( CheckboxControl, {
                    label: __( 'Skip duplicate honoree/donor combinations', 'starter-shelter' ),
                    checked: skipDuplicates,
                    onChange: setSkipDuplicates
                }),

                el( 'div', { style: { marginTop: '16px' } },
                    el( FileUploadArea, {
                        onFileSelect: handleFileSelect,
                        file: file
                    })
                ),

                isLoading && el( 'div', { style: { textAlign: 'center', padding: '20px' } },
                    el( Spinner ),
                    el( 'p', {}, __( 'Parsing file...', 'starter-shelter' ) )
                ),

                preview && el( 'div', { style: { marginTop: '16px' } },
                    el( Notice, { status: 'info', isDismissible: false },
                        el( 'div', {},
                            el( 'strong', {}, sprintf( __( 'Found %d memorials', 'starter-shelter' ), preview.total_rows ) ),
                            el( 'div', { style: { fontSize: '13px', marginTop: '4px' } },
                                sprintf( 
                                    __( '%d people, %d pets • Months: %s', 'starter-shelter' ), 
                                    preview.person_count, 
                                    preview.pet_count,
                                    preview.months_found.join( ', ' )
                                )
                            )
                        )
                    ),
                    
                    // Preview table
                    preview.preview_rows && preview.preview_rows.length > 0 && el( 'div', { 
                        style: { marginTop: '12px', maxHeight: '300px', overflowY: 'auto' } 
                    },
                        el( 'table', { className: 'widefat', style: { fontSize: '13px' } },
                            el( 'thead', {},
                                el( 'tr', {},
                                    el( 'th', {}, __( 'In Memory Of', 'starter-shelter' ) ),
                                    el( 'th', {}, __( 'By', 'starter-shelter' ) ),
                                    el( 'th', {}, __( 'Type', 'starter-shelter' ) ),
                                    el( 'th', {}, __( 'Month', 'starter-shelter' ) )
                                )
                            ),
                            el( 'tbody', {},
                                preview.preview_rows.map( function( row, i ) {
                                    return el( 'tr', { key: i },
                                        el( 'td', {}, row.honoree_name ),
                                        el( 'td', {}, row.donor_name ),
                                        el( 'td', {}, 
                                            el( 'span', { 
                                                style: { 
                                                    background: row.memorial_type === 'pet' ? '#e8f5e9' : '#e3f2fd',
                                                    color: row.memorial_type === 'pet' ? '#2e7d32' : '#1565c0',
                                                    padding: '2px 6px',
                                                    borderRadius: '3px',
                                                    fontSize: '11px'
                                                }
                                            }, row.memorial_type )
                                        ),
                                        el( 'td', {}, row.month )
                                    );
                                })
                            )
                        ),
                        preview.total_rows > 20 && el( 'p', { 
                            style: { textAlign: 'center', color: '#757575', marginTop: '8px' } 
                        }, sprintf( __( '... and %d more', 'starter-shelter' ), preview.total_rows - 20 ) )
                    )
                ),

                results && el( 'div', { style: { marginTop: '16px' } },
                    el( Notice, { 
                        status: results.errors > 0 ? 'warning' : 'success', 
                        isDismissible: false 
                    },
                        el( 'div', {},
                            el( 'strong', {}, __( 'Import Complete!', 'starter-shelter' ) ),
                            el( 'div', { style: { marginTop: '8px' } },
                                el( 'div', {}, sprintf( __( 'Created: %d memorials', 'starter-shelter' ), results.created ) ),
                                el( 'div', {}, sprintf( __( 'Skipped: %d duplicates', 'starter-shelter' ), results.skipped ) ),
                                el( 'div', {}, sprintf( __( 'New donors created: %d', 'starter-shelter' ), results.donors_created ) ),
                                results.errors > 0 && el( 'div', { style: { color: '#d63638' } }, 
                                    sprintf( __( 'Errors: %d', 'starter-shelter' ), results.errors ) 
                                )
                            )
                        )
                    )
                )
            ),
            ( preview && ! results ) && el( CardFooter, {},
                el( Flex, { justify: 'flex-end' },
                    el( Button, {
                        variant: 'secondary',
                        onClick: function() { 
                            setFile( null ); 
                            setPreview( null ); 
                        },
                        disabled: isImporting
                    }, __( 'Cancel', 'starter-shelter' ) ),
                    el( Button, {
                        variant: 'primary',
                        onClick: handleImport,
                        isBusy: isImporting,
                        disabled: isImporting
                    }, isImporting 
                        ? __( 'Importing...', 'starter-shelter' ) 
                        : sprintf( __( 'Import %d Memorials', 'starter-shelter' ), preview.total_rows )
                    )
                )
            )
        );
    }

    /**
     * Main App Component
     */
    function ImportExportApp() {
        var counts = sdImportExport.counts || {};

        function handleExport( type, option ) {
            var form = document.createElement( 'form' );
            form.method = 'POST';
            form.action = sdImportExport.adminPostUrl;
            
            function addField( name, value ) {
                var input = document.createElement( 'input' );
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild( input );
            }

            addField( '_wpnonce', sdImportExport.exportNonce );
            addField( 'action', 'sd_export');
            addField( 'export_type', type);
            if ( option && option !== 'all' ) {
                addField( type === 'donations' ? 'date_range' : 'status', option );
            }

            document.body.appendChild( form );
            form.submit();
        }

        var tabs = [
            { name: 'export', title: __( 'Export Data', 'starter-shelter' ) },
            { name: 'import', title: __( 'Import Data', 'starter-shelter' ) }
        ];

        return el( 'div', { className: 'sd-import-export-app' },
            el( 'h1', { className: 'wp-heading-inline' }, __( 'Import / Export', 'starter-shelter' ) ),
            el( 'hr', { className: 'wp-header-end' } ),
            
            el( TabPanel, {
                className: 'sd-import-export-tabs',
                tabs: tabs,
                initialTabName: 'export'
            }, function( tab ) {
                if ( tab.name === 'export' ) {
                    return el( 'div', { style: { marginTop: '20px' } },
                        el( 'p', { style: { color: '#757575', marginBottom: '20px' } },
                            __( 'Export your shelter data to CSV files.', 'starter-shelter' )
                        ),
                        el( 'div', { 
                            style: { 
                                display: 'grid', 
                                gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', 
                                gap: '20px' 
                            }
                        },
                            el( ExportCard, {
                                icon: '💰',
                                title: __( 'Donations', 'starter-shelter' ),
                                description: __( 'All donation records.', 'starter-shelter' ),
                                count: counts.donations || 0,
                                options: [
                                    { value: 'all', label: __( 'All Time', 'starter-shelter' ) },
                                    { value: 'this_year', label: __( 'This Year', 'starter-shelter' ) },
                                    { value: 'last_year', label: __( 'Last Year', 'starter-shelter' ) }
                                ],
                                onExport: function( opt ) { handleExport( 'donations', opt ); },
                                isExporting: false
                            }),
                            el( ExportCard, {
                                icon: '🏅',
                                title: __( 'Memberships', 'starter-shelter' ),
                                description: __( 'Membership records.', 'starter-shelter' ),
                                count: counts.memberships || 0,
                                options: [
                                    { value: 'all', label: __( 'All', 'starter-shelter' ), count: counts.memberships },
                                    { value: 'active', label: __( 'Active', 'starter-shelter' ), count: counts.memberships_active },
                                    { value: 'expired', label: __( 'Expired', 'starter-shelter' ), count: counts.memberships_expired }
                                ],
                                onExport: function( opt ) { handleExport( 'memberships', opt ); },
                                isExporting: false
                            }),
                            el( ExportCard, {
                                icon: '👥',
                                title: __( 'Donors', 'starter-shelter' ),
                                description: __( 'Donor profiles.', 'starter-shelter' ),
                                count: counts.donors || 0,
                                options: [],
                                onExport: function() { handleExport( 'donors' ); },
                                isExporting: false
                            }),
                            el( ExportCard, {
                                icon: '❤️',
                                title: __( 'Memorials', 'starter-shelter' ),
                                description: __( 'Memorial tributes.', 'starter-shelter' ),
                                count: counts.memorials || 0,
                                options: [],
                                onExport: function() { handleExport( 'memorials' ); },
                                isExporting: false
                            })
                        )
                    );
                } else {
                    return el( 'div', { style: { marginTop: '20px' } },
                        el( 'p', { style: { color: '#757575', marginBottom: '20px' } },
                            __( 'Import data from CSV files.', 'starter-shelter' )
                        ),
                        el( 'div', { 
                            style: { 
                                display: 'grid', 
                                gridTemplateColumns: 'repeat(auto-fill, minmax(450px, 1fr))', 
                                gap: '20px' 
                            }
                        },
                            el( ImportCard, {
                                icon: '👥',
                                title: __( 'Import Donors', 'starter-shelter' ),
                                description: __( 'Import donor records.', 'starter-shelter' ),
                                requiredColumns: [ 'email', 'first_name', 'last_name' ],
                                optionalColumns: [ 'phone', 'address_line_1', 'city', 'state' ],
                                templateUrl: sdImportExport.templateUrls.donors,
                                importAction: 'donors',
                                options: [
                                    { key: 'update_existing', label: __( 'Update existing donors', 'starter-shelter' ), default: true },
                                    { key: 'skip_errors', label: __( 'Skip rows with errors', 'starter-shelter' ), default: true }
                                ]
                            }),
                            el( ImportCard, {
                                icon: '💰',
                                title: __( 'Import Donations', 'starter-shelter' ),
                                description: __( 'Import donation records.', 'starter-shelter' ),
                                requiredColumns: [ 'email', 'amount', 'date' ],
                                optionalColumns: [ 'first_name', 'last_name', 'allocation' ],
                                templateUrl: sdImportExport.templateUrls.donations,
                                importAction: 'donations',
                                options: [
                                    { key: 'create_donors', label: __( 'Create donors if not found', 'starter-shelter' ), default: true },
                                    { key: 'skip_errors', label: __( 'Skip rows with errors', 'starter-shelter' ), default: true }
                                ]
                            }),
                            el( ImportCard, {
                                icon: '❤️',
                                title: __( 'Import Memorials', 'starter-shelter' ),
                                description: __( 'Import memorial donations (in memory/honor).', 'starter-shelter' ),
                                requiredColumns: [ 'email', 'honoree_name', 'amount', 'date' ],
                                optionalColumns: [ 'memorial_type', 'tribute_message', 'pet_species', 'notify_family_email' ],
                                templateUrl: sdImportExport.templateUrls.memorials,
                                importAction: 'memorials',
                                options: [
                                    { key: 'create_donors', label: __( 'Create donors if not found', 'starter-shelter' ), default: true },
                                    { key: 'skip_errors', label: __( 'Skip rows with errors', 'starter-shelter' ), default: true }
                                ]
                            }),
                            el( ImportCard, {
                                icon: '🏅',
                                title: __( 'Import Memberships', 'starter-shelter' ),
                                description: __( 'Import membership records (individual, family, business).', 'starter-shelter' ),
                                requiredColumns: [ 'email', 'membership_type', 'tier', 'amount', 'start_date', 'end_date' ],
                                optionalColumns: [ 'business_name', 'business_website', 'business_description' ],
                                templateUrl: sdImportExport.templateUrls.memberships,
                                importAction: 'memberships',
                                options: [
                                    { key: 'create_donors', label: __( 'Create donors if not found', 'starter-shelter' ), default: true },
                                    { key: 'skip_errors', label: __( 'Skip rows with errors', 'starter-shelter' ), default: true }
                                ]
                            }),
                            el( LegacyMemorialImport )
                        )
                    );
                }
            })
        );
    }

    // Initialize
    function init() {
        var container = document.getElementById( 'sd-import-export-root' );
        
        // Debug output
        console.log( 'Starter Shelter Import/Export: Initializing...' );
        console.log( 'Container found:', !!container );
        console.log( 'sdImportExport defined:', typeof sdImportExport !== 'undefined' );
        
        if ( typeof sdImportExport !== 'undefined' ) {
            console.log( 'Config:', sdImportExport );
        }
        
        if ( ! container ) {
            console.error( 'Starter Shelter: Could not find #sd-import-export-root element.' );
            return;
        }
        
        if ( typeof sdImportExport === 'undefined' ) {
            console.error( 'Starter Shelter: sdImportExport config not defined. Script may not be properly enqueued.' );
            container.innerHTML = '<div class="notice notice-error"><p>Import/Export configuration not loaded. Please refresh the page.</p></div>';
            return;
        }
        
        try {
            console.log( 'Starter Shelter: Rendering app...' );
            render( el( ImportExportApp ), container );
            console.log( 'Starter Shelter: App rendered successfully.' );
        } catch ( e ) {
            console.error( 'Starter Shelter render error:', e );
            container.innerHTML = '<div class="notice notice-error"><p>Error loading Import/Export UI: ' + e.message + '</p></div>';
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
