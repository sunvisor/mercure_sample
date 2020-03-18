/**
 * Main Controller
 */
Ext.define('App.view.main.MainController', {
    extend: 'Ext.app.ViewController',

    alias: 'controller.main',

    onRequestButton: function () {
        const vm = this.getViewModel();

        vm.sendRequest();
    },

    renderStatus(value) {
        const icon = value === 'done' ? '' : 'fa-sync fa-spin';
        return `<i class="fa ${icon}"></i> ${value}`;
    }
});
