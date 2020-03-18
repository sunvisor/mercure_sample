/**
 * Main ViewModel
 */
Ext.define('App.view.main.MainModel', {
    extend: 'Ext.app.ViewModel',

    alias: 'viewmodel.main',

    requires: [
        'Ext.data.proxy.Memory'
    ],

    data: {
        name: 'App',
        messageId: null
    },

    stores: {
        requests: {
            proxy: 'memory'
        }
    },

    marcureUrl: 'http://localhost:3000/.well-known/mercure',

    sendRequest() {
        const store = this.getStore('requests');

        // 非同期のレクエストにPOSTする
        // レスポンスはすぐに返る
        Ext.Ajax.request({
            url: '/request',
            params: {'type': 1}
        }).then(result => {
            const data = Ext.decode(result.responseText);
            this.set('messageId', data.messageId);
            // EventSource を作ってサーバーからの通知を subscribe する
            this.subscribe(data.messageId);
            // リクエストした結果をグリッドに表示する
            store.add({
                messageId: data.messageId,
                state: 'requested'
            })
        }).catch(result => console.error('error', result));
    },

    subscribe(id) {
        const url = new URL(`${this.marcureUrl}?topic=${id}`),
              eventSource = new EventSource(url),
              store = this.get('requests');

        eventSource.onmessage = e => {
            // サーバーから通知があったときの処理
            const data = Ext.decode(e.data),
                  messageId = data.messageId,
                  record = store.findRecord('messageId', messageId);

            // ステータスを更新する
            record.set('state', data.state);
            eventSource.close();
        }
    }
});
