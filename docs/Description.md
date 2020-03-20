# Symfony でバッググラウンドジョブを実行する

- 実現したいこと
- Step1: Messenger コンポーネントによるバックグラウンド実行
- Step2: Mercure コンポーネントによるサーバーからの通知
- 参考URL

## 実現したいこと

サーバーで重い作業が必要になることがあります。 数分の処理でもタイムアウトを引き起こすことがあります。 Ajax の場合、リクエストの際に設定したタイムアウトの時間内に終わらないかもしれません。 ダウンロード系の場合ではブラウザーのタイムアウト設定に依存します。 そもそもユーザーに対して何のレスポンスもない状態が続くのは精神的にもよくありません。

それを何とかするためには、サーバー側で重い作業をする時にバックグラウンドで処理させたいと考えると思います。
次のような流れです。

- リクエストが来る
- ジョブを登録
- すぐレスポンスを返す
- その後ジョブがバックグラウンドで実行される

いわゆる「非同期処理」というものですね。これを実現する方法はいろいろありますが、簡単なのは1分単位などで CRON を使ってバックグラウンドタスクを実行させるというものです。Symfony なら Command を作って、その実行を CRON に委ねます。これの欠点は、リクエストが来てから、次の CRON 実行時刻までの待ちが必ず発生するという点です。バックグラウンドに回した処理の中には短い時間で完了するものもあるような場合、処理時間が無駄に伸びてしまうことが発生します。Symfony4 から導入された Messenger コンポーネントを使うとスマートに解決できます。キューを使って処理をバックグラウンドに回して、レスポンスをすぐに返せるようになります。

非同期処理によってバックグラウンドでのジョブの実行ができるようになったら、今度は、それが終わったら通知が欲しくなります。長い処理なら、その進捗を知りたくなるかもしれません。これまでのやり方ですと、バックグラウンドジョブの状態を調べる API を用意して、それをクライアントからポーリングで確認する、という方法が考えられますね。
でもスマートにキメるなら、サーバー側からクライアントに通知を出したいものです。
[**SSE (Server Sent Event)**](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events) を使えば、サーバー側からクライアントへ通知を送ることができるようになります。
Symfony では Mercure コンポーネントが、それを実現します。

ということで、この2つのコンポーネントを使って、バックグラウンド機能を実装してみましょう。

以下の記事は macOS での作業ログです。他の OS の場合は適宜ご自分の環境に読み替えてください。

## Step1: Messenger コンポーネントによるバックグラウンド実行

[Messenger コンポーネント](https://symfony.com/doc/current/components/messenger.html) は、バスを通じて、アプリケーションからバッググラウンドジョブ (Handler) に処理を依頼する仕組みです。
アプリケーションは、バスにメッセージを送ります。仕事の依頼ですね。
標準では、送られたメッセージはその場で処理されます。この場合は非同期ではなく同期です。
[トランスポート](https://symfony.com/doc/current/messenger.html#messenger-transports-config)
を設定すると、キューはそのトランスポートに送られて、非同期処理が実現できるようになります。


### Messenger バンドル

Symfony に Messenger バンドルをインストールします。

```bash
symfony composer require messenger
```

### コントローラーのメソッド

まずは、時間がかかりそうな処理を受け付ける API のコントローラーメソッドを作ります。ここでは `request` というエンドポイントとしています。

```php
    /**
     * 非同期のリクエスト
     *
     * @Route("/request", name="async_request")
     * @param Request             $request
     * @param MessageBusInterface $bus
     * @return JsonResponse
     */
    public function requestAction(Request $request, MessageBusInterface $bus)
    {
        $type = $request->request->get('type');
        // 通知オブジェクトを作成
        $notification = new RequestNotification($type);
        // bus に処理を依頼
        $bus->dispatch($notification);
        // メッセージID を返す
        return new JsonResponse([
            'messageId' => $notification->getMessageId(),
            'success' => true
        ]);
    }
```

- `MessageBusInterface` を注入しています
- リクエストの POST パラメーターから値を取り出して、それをセットした通知オブジェクト (後述) を作成します
- それを `MessageBus` の `dispatch` メソッドに渡します
    - これでメッセージがバスに送られます
- メッセージを送ったらそのままレスポンスを返します
    - メッセージをクライアントがメッセージを特定できるように `messageId` を返しています

### メッセージオブジェクト

コントローラーでインスタンスを生成しているメッセージオブジェクトのクラスは次のようなものです。メッセージはロジックを持たないデータオブジェクトクラスです。メッセージのオブジェクトにはこれといった決まりはありませんが、シリアライズされてキューに保存されるので、シリアライズできるシンプルなデータだけをもたせることになります。
  
```php
class RequestNotification
{
    /**
     * @var int
     */
    private $type;
    /**
     * @var string
     */
    private $messageId;

    /**
     * RequestNotification constructor.
     * @param int    $type
     */
    public function __construct(int $type)
    {
        $this->type = $type;
        // メッセージID を生成
        $this->messageId = uniqid();
    }

    /**
     * @return string
     */
    public function getMessageId()
    {
        return $this->messageId;
    }

    /**
     * @return int
     */
    public function getType()
    {
        return $this->type;
    }
}
```

- コンストラクターの中で `messageId` を生成しています
- これがメッセージを特定する ID になります

### メッセージハンドラー

`dispatch` されたメッセージを読み解いて、処理を実行するのがメッセージハンドラーの役目です。

```php
class RequestHandler implements MessageHandlerInterface
{
    public function __invoke(RequestNotification $message)
    {
        $data = [
            'messageId' => $message->getMessageId(),
            'type' => $message->getType(),
            'state' => 'in_progress'
        ];
        $id = $message->getMessageId();
        // 作業中のステータスでファイルを書く
        $this->writeContents($id, $data);
        // 処理に時間家がかるのを演出
        sleep(10);
        // ステータスを完了に変更
        $data['state'] = 'done';
        $this->writeContents($id, $data);
    }

    /**
     * @param string $id
     * @param array  $data
     */
    private function writeContents(string $id, array $data): void
    {
        $fileName = __DIR__ . "/../../var/result{$id}.txt";
        $contents = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($fileName, $contents);
    }
}
```

- `MessageHandlerInterface` インターフェイスを実装します
    - このインターフェイスには何も定義されていないんですが、Symfony の DI がこれを見てメッセージハンドラーだとわかるんですね
- `__invoke` メソッドに実際の処理を書きます
    - 引数にメッセージが渡されます
    - 引数のタイプヒントによって、このハンドラーが `RequestNotification` メッセージと関連するハンドラーだと判断さ  れます
    - 簡単な処理として、ファイルに何かを書き込むというジョブを実行しています
- 最初の API を呼び出し、`dispatch` されると、このハンドラーが実行されて、ファイルが書き出されます
- 途中で `sleep` を挟んで、時間がかかる処理を演出しています

### RabbitMQ の導入

現在の段階では、トランスポートが設定されていないので同期で処理されます。つまりレスポンスが返るまで10秒待たされます。重いジョブを依頼すると、レスポンスまでの時間はジョブが終了までかかります。
目的の非同期を実現させるためにはトランスポートを指定しなければなりません。今回はトランスポートとして [RabbitMQ](https://www.rabbitmq.com/) を使うことにします。

開発環境では docker で動かしましょう。

```
version: "3"
services:
  rabbitmq:
    image: rabbitmq:3.7-management
    ports: [5672, 15672]
```

上記が *docker-compose.yaml* の内容です。`docker-composer up` して起動します。

- **注意**: PHP に ampq extension がインストールされていないと動作しません。また ampq extension を入れるには、ローカルに RabbitMQ が入っていないといけません。 phpbrew で拡張を入れる場合は次のようにします。

```bash
brew install rabbitmq-c
phpbrew ext install amqp
```

*config/packages* にある *messenger.yaml* に `transport` と `routing` を定義します

```yaml
framework:
    messenger:
        transports:
            async: '%env(RABBITMQ_DSN)%'
        routing:
            'App\Message\RequestNotification': async
```

- `transport` で `async` というトランスポートを RabbitMQ に設定しています
- `routing` で、`RequestNotification` クラスを `async` にバインドしています
- これで、`RequestNotification` メッセージは RabbitMQ で実行されることになります
- 他の通知がある場合には、`routing` にて、通知クラスとトランスポートをバインドします

バックグラウンドで動作をさせるためには、`messenger:consume` コマンドを実行する必要があります。バックグラウンド動作ですので別プロセスの実行が必要ですよね。

```bash
symfony console messenger:consume -vv
```

- `-vv` はログを細かく表示させるためのオプションです

ここまでやって API を呼び出してみると、レスポンスがすぐに返ってくるようになります。
サーバー上で *var* ディレクトリを見るとファイルが作成されています。呼び出されたすぐには `"state": "in_progress"` だったものが、10秒経ったら、`"state": "done"` に変わります

本番環境では、普通にコマンドを実行すると、なんらかの理由でおちてしまったり、メモリーリークが発生したりして困ってしまうことがあります。ですから [Supervisor](http://supervisord.org/) を使って、障害時の再起動や定期的な再起動を設定するなどします。[公式ガイド](https://symfony.com/doc/current/messenger.html#supervisor-configuration) に解説がありますので参考にしてください。

### 状態を取得する API の実装

バックグラウンドでジョブが実行できるのはいいのですが、ジョブが終わったのかどうなのか知る方法がないといけません。
次のような API を書いて、内容を確認できるようにしましょう。
この API をコールすることで、ジョブの状態を知ることができます。必要であれば、進捗状況も返すようにすれば、クライアント側でプログレスバーなどを表示することもできますね。

```php
    /**
     * 処理の状態を確認
     *
     * @Route("/read/{id}", name="async_read")
     * @param $id
     * @return JsonResponse
     */
    public function readAction($id)
    {
        $fileName = __DIR__ . "/../../var/result{$id}.txt";
        if (!file_exists($fileName)) {
            throw $this->createNotFoundException('cannot found data');
        }
        // 非同期でつくられるファイルの内容を取得して返す
        $content = file_get_contents($fileName);
        $result = json_decode($content);
        return new JsonResponse($result);
    }
```

### 本番環境では

コンシューマーは、 Supervisor などで起動する。

## Step2: Mercure コンポーネントによるサーバーからの通知

Messenger コンポーネントのおかげで非同期処理を実現することができました。ここまでのことが実装できたら、ポーリングを使えば状況確認もできます。十分実用的です。
次に、SSE を使ってサーバー側からの通知を実現しましょう。[Mercure コンポーネント](https://symfony.com/doc/current/components/mercure.html) を使います。

[Mercure](https://mercure.rocks/) の**ハブ**を起動しておいて、アプリケーションからそのハブに POST することで、クライアントに SSE を送信します。
クライアント側では [`EventSource`](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events) を使ってサーバーイベントをリスニングします。

### Mercure コンポーネントのインストール

Symfony の Mercure コンポーネントをインストールします。

```bash
composer require mercure
```

### Mercure のセットアップ

Mercure 公式の Docker イメージがありますので、それを起動するようにします。
*docker-compose.yaml* に marcure の定義を追加します。

```yaml
version: "3"
services:
  rabbitmq:
    image: rabbitmq:3.7-management
    ports: [5672, 15672]
  mercure:
    image: dunglas/mercure
    environment:
      # ここはあなたが決めたキーにします
      - JWT_KEY=sunvisor
      - DEMO=1
      - ALLOW_ANONYMOUS=1
      - HEARTBEAT_INTERVAL=30s
      - ADDR=:3000
      - CORS_ALLOWED_ORIGINS=*
      - PUBLISH_ALLOWED_ORIGINS=http://mercure:3000,http://localhost:3000
    ports:
      - "3000:3000"
    networks:
      - backend
networks:
  backend:
    driver: bridge
```

#### JWT_TOKEN を取得して環境変数にセット

[Mercure の公式ガイド](https://symfony.com/doc/current/mercure.html) にある、[サンプル JWT のリンク](https://jwt.io/#debugger-io?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.iHLdpAEjX4BqCsHJEegxRmO-Y6sMxXwNATrQyRNt3GY) を開いて、*"your-256-bit-secret"* となっている欄に、上記の *docker-compose.yaml* で `JWT_KEY` の項目に設定したキーを入力します。
すると、"Encoded" の欄が更新されますので、それが `JWT_TOKEN` となります。

*.env.local* に次の環境変数を定義します。

```
MERCURE_PUBLISH_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_TOKEN='上記で生成したJWT_TOKEN'
```

`docker-compose` を再起動すると、Mercure のハブが起動します。

### 通知の送信

通知を送ることを publish といいます。公式ガイドのサンプルコードを次に示します。

```php
    public function __invoke(PublisherInterface $publisher): Response
    {
        $update = new Update(
            'http://example.com/books/1',
            json_encode(['status' => 'OutOfStock'])
        );

        // The Publisher service is an invokable object
        $publisher($update);

        return new Response('published!');
    }
```

- `PublisherInterface` を注入しています
- 注入された `publisher` を使って、更新通知を送ります。
    - 引数には `Symfony\Component\Mercure\Update` クラスのインスタンスを渡します
    - `Update` の1つ目の引数は *topic* です。
    - この *topic* は、IRI (Internationalized Resource Identifier、RFC 3987) である必要があります。
    - ディスパッチされるリソースの一意の識別子です


#### `RequestNotification` クラスの変更

*topic* を IRI にするために、メッセージに URL を渡すようにします。そのために `RequestNotification` クラスを少し変更します。

実は、*topic* は、IRI でなくても普通に動作します。が、ここは IRI にしなければならない、というルールを守って行こうと思います。どうせなら、役に立つ URL を送りたいので、`read` API の URL を渡すように作ることにします。

```php
class RequestNotification
{
    /**
     * @var int
     */
    private $type;
    /**
     * @var string
     */
    private $messageId;
    /**
     * @var string
     */
    private $topic;

    /**
     * RequestNotification constructor.
     * @param int    $type
     * @param string $url
     */
    public function __construct(int $type, string $url)
    {
        $this->type = $type;
        // メッセージID を生成
        $this->messageId = uniqid();
        $this->topic = $url . $this->messageId;
    }

    /**
     * @return string
     */
    public function getMessageId()
    {
        return $this->messageId;
    }

    /**
     * @return int
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getTopic()
    {
        return $this->topic;
    }
}
```

- コンストラクタに URL を渡すと、それと `messageId` を元に topic を作ります。

#### `requestAction` コントローラーメソッドの変更

`RequestNotification` クラスが URL を受け取るようになったので、`requestAction` コントローラーメソッドを変更します。

```php
    /**
     * 非同期のリクエスト
     *
     * @Route("/request", name="async_request")
     * @param Request             $request
     * @param MessageBusInterface $bus
     * @return JsonResponse
     */
    public function requestAction(Request $request, MessageBusInterface $bus)
    {
        $type = $request->request->get('type');
        $url = "{$request->getSchemeAndHttpHost()}/read/";
        // 通知オブジェクトを作成
        $notification = new RequestNotification($type, $url);
        // bus に処理を依頼
        $bus->dispatch($notification);
        // メッセージID を返す
        return new JsonResponse([
            'messageId' => $notification->getMessageId(),
            'topic'     => $notification->getTopic(),
            'success'   => true
        ]);
    }
```

 - RequestNotification` に URL を渡すようにします
   - 先程の方針で `RequestNotification` の `url` には、`read` リクエストの URL を渡しています
 - レスポンスに `topic` を返すようにします
   - クライアント側では、この `topic` を使って通知を購読します
 
#### *RequestHandler* クラスを修正します。

`RequestHandler` クラスで、クライアントへの通知を送信するように修正します。

```php
class RequestHandler implements MessageHandlerInterface
{
    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * RequestHandler constructor.
     * @param PublisherInterface $publisher
     */
    public function __construct(PublisherInterface $publisher)
    {
        // push 通知を行う publisher を注入
        $this->publisher = $publisher;
    }

    public function __invoke(RequestNotification $message)
    {
        $data = [
            'messageId' => $message->getMessageId(),
            'type' => $message->getType(),
            'state' => 'in_progress'
        ];
        $id = $message->getMessageId();
        $topic = $message->getTopic();
        // 作業中のステータスでファイルを書く
        $this->writeContents($id, $data);
        // 処理に時間家がかるのを演出
        sleep(10);
        // ステータスを完了に変更
        $data['state'] = 'done';
        $this->writeContents($id, $data);
        // push 通知を送る
        ($this->publisher)(new Update($topic, json_encode($data)));
    }

    /**
     * @param string $id
     * @param array  $data
     */
    private function writeContents(string $id, array $data): void
    {
        $fileName = __DIR__ . "/../../var/result{$id}.txt";
        $contents = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($fileName, $contents);
    }
}
```

- コンストラクタで `PublisherInterface` を注入しています
- 注入されたこの `publisher` を使って、更新通知を送ります
    - `Update` の1つ目の引数は *topic* ですので、`RequestNotification` から topic を取り出してセットします
    - 2つ目の引数は、クライアントに送るデータをセットします

### 通知を受け取る (クライアント側のJavaScript)

サーバーから SSE を使って送られた通知を受け取るには [`EventSource`](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events) を使います。
Mdn のページの Browser compatibility を見ておわかりのように、お約束ですが IE と古い Edge では動作しません。ですが [polyfill](https://github.com/Yaffle/EventSource) がありますので心配いりません。

クライアント側のプログラムは、[Sencha Ext JS](https://www.sencha.com/products/extjs/) で記述しました。

#### *Main.js*

ビューのクラスです。

```javascript
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
```

- ツールバーに Requesst ボタンを配置
- 画面中央にはグリッドを配置しています
    - このグリッドにリクエストの状態を表示します

#### *MainController.js*

ビューのコントローラーです。イベントを処理します。

```javascript
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
```

- `onRequestButton` は Request ボタンがクリックされたときの処理です
    - ViewModel の `sendRequest` メソッド (後述) を呼び出しています
- `renderStatus` は、ステータスカラムを描画する処理です
    - state が 'done' の場合は、ロード中を示すアイコンを表示させています

#### *MainModel.js*

ビューモデルです。サーバーとの通信とデータの保持を担当します。

```javascript
Ext.define('App.view.main.MainModel', {
    extend: 'Ext.app.ViewModel',

    alias: 'viewmodel.main',

    requires: [
        'Ext.data.proxy.Memory'
    ],

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
            url   : '/request',
            params: {'type': 1}
        }).then(result => {
            const data = Ext.decode(result.responseText);
            // EventSource を作ってサーバーからの通知を subscribe する
            this.subscribe(data.topic);
            // リクエストした結果をグリッドに表示する
            store.add({
                messageId: data.messageId,
                state    : 'requested'
            })
        }).catch(result => console.error('error', result));
    },

    subscribe(topic) {
        const url = new URL(this.marcureUrl);
        url.searchParams.append('topic', topic);

        const eventSource = new EventSource(url.toString()),
              store       = this.get('requests');

        eventSource.onmessage = e => {
            // サーバーから通知があったときの処理
            const data      = Ext.decode(e.data),
                  messageId = data.messageId,
                  record    = store.findRecord('messageId', messageId);

            // ステータスを更新する
            record.set('state', data.state);
            eventSource.close();
        }
    }
});
```

- `sendRequest` メソッドでは、API を呼び出しています。
- レスポンスを受け取ったら (`then` の中の処理)
    - `subscribe` メソッドを呼び出してから
    - リクエストの内容をグリッドに追加 (正しくはグリッドと紐付けられた `store` に追加) しています
- `subscribe` メソッドでは、渡された topic の url に対して、通知の待受をします
    - EventSource のインスタンスを作成し
    - `onmessage` に、通知があった時に実行する処理を記述しています
- `onmessage` では、
    - `messageId` に対応するレコードを取得して、`state` を変更しています
    - その後、`close` メソッドを呼び出してイベントの購読を終えています
    
### 実行画面

実行した画面のキャプチャです。

![](mercure.gif)

GIF アニメの長さを抑えるために、 `RequestHandler` での待ち時間を短めにして実行しています。非同期処理によって、適切にハンドリングできているのがわかると思います。

## まとめ

Symfony の Messenger コンポーネントと、Mercure コンポーネントを使って、非同期処理と完了の通知を実現できました。開発対象の要件によって、この両方が必要な場合もあるでしょうし、通知はいらないけど非同期処理はほしいよね、という場合もあるでしょう。でも、これでどちらの場合にも対応できるようになりました。

Messenger コンポーネントの方は、トランスポートを設定しなければ同期で動きます。
トランスポートを用意しなくても、標準で同期で実行されるということは、この先、非同期にする必要があるかもしれないタスクは、最初からメッセージを通じて処理するようにしておけば、必要に応じてトランスポートの定義をするだけで非同期に変更することができるということになります。少々のオーバーヘッドはあるかもしれませんが、拡張性としてはいい感じがします。また、プログラムの構造の飢えでもリクエストの受付と、リクエストの実行を分離するということが自然とできて良いかもしれません。

## 参考URL

- [Messenger: Sync & Queued Message Handling](https://symfony.com/doc/current/messenger.html)
- [The Messenger Component](https://symfony.com/doc/current/components/messenger.html)
- [Symfony Gets Real-time Push Capabilities!](https://symfony.com/blog/symfony-gets-real-time-push-capabilities)
- [Pushing Data to Clients Using the Mercure Protocol](https://symfony.com/doc/current/mercure.html)
- [Marcure Component](https://symfony.com/doc/current/components/mercure.html)
- [Mercure](https://mercure.rocks/)
- [symfony/mercure](https://github.com/symfony/mercure)
- [symfony/mercure-bundle](https://github.com/symfony/mercure-bundle)
- [Symfony4+ で Server-Sent events を使ってみよう](https://tech.quartetcom.co.jp/2019/12/23/symfony-sse/)
- [Instant realtime notifications with Symfony and Mercure](https://medium.com/@stefan.poeltl/instant-realtime-notifications-with-symfony-and-mercure-e45270f7c8a5)
- [Pushing Live Updates Using the Mercure Protocol: Api Platform](https://api-platform.com/docs/core/mercure/)
- [Real-Time Notifications With Mercure](https://thedevopsguide.com/real-time-notifications-with-mercure/)