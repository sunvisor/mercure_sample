/**
 * Main View
 */
Ext.define('App.view.main.Main', {
    extend: 'Ext.Panel',
    xtype : 'app-main',

    requires: [
        'App.view.main.MainController',
        'App.view.main.MainModel',
        'Ext.grid.Grid',
        'Ext.layout.Fit'
    ],

    controller: 'main',
    viewModel : 'main',

    layout: 'fit',

    title: 'Sample of Async Request and Push Message',

    tbar: [
        {
            text   : 'Request',
            handler: 'onRequestButton',
            tooltip: 'Send request to server'
        }
    ],

    items: [
        {
            xtype  : 'grid',
            bind   : {
                store: '{requests}'
            },
            columns: [
                {
                    text     : 'Message ID',
                    dataIndex: 'messageId',
                    flex     : 1,
                },
                {
                    text     : 'Status',
                    cell     : {
                        encodeHtml: false
                    },
                    dataIndex: 'state',
                    flex     : 1,
                    renderer: 'renderStatus'
                }
            ]
        }
    ]
});
