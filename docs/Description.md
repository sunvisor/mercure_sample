# Running a background job with Symfony

- What we want to achieve.
- Step 1: Background execution with the Messenger component
- Step 2: Notification from the server by the Mercure component
- Reference URL

## What we want to achieve

You may need to do some heavy work on the server. Even a few minutes of processing can cause a timeout. In Ajax, it may not be finished within the timeout set at the time of the request. If it is a downloader, it depends on your browser's timeout setting. To begin with, it's not good for mental health if you don't get any response from users.

In order to do something about it, you'd think that you'd want to let the server side do the heavy lifting in the background.
It goes like this.

- A request comes in.
- Register a job
- I'll get right back to you.
- Then the job runs in the background.

This is the so-called "asynchronous processing", isn't it? There are many ways to do this, but one of the easiest is to use CRON to execute a background task every minute or so, in symfony, you can create a Command and let CRON execute it. The disadvantage of this is that there is always a wait from the arrival of the request until the next CRON execution time.
If some of the processing in the background takes a short time to complete, the processing time may be unnecessarily long.
The Messenger component, introduced from Symfony4, is a smart solution. You can use a cue to turn the process into the background and get a response right away.

Once the asynchronous processing allows you to run the job in the background, you now want to be notified when it's done. If it's a long process, you might want to know how it's progressing. One way to do this is to provide an API to check the status of the background job, and then poll the client to see if it's working.
But if you want to be smart, you want to send notifications to your clients from the server side. 
[**SSE (Server Sent Event)**](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events) allows you to send notifications from the server side to the client. In Symfony, the Mercure component does just that.

So, let's use these two components to implement the background functionality.

The following article is a work log on macOS. If you are using another OS, please change the reading to your own environment.

## Step1: Background execution by the Messenger component

[The Messenger component](https://symfony.com/doc/current/components/messenger.html) is a mechanism for requesting a bag ground job (Handler) to be processed by an application through a bus. The application sends a message to the bus. It's a job offer. By default, messages sent are processed on the fly. In this case, it is synchronous, not asynchronous. If you set a [transport](https://symfony.com/doc/current/messenger.html#messenger-transports-config), the queue will be sent to that transport to allow asynchronous processing.

### Messenger Bundle

Install the Messenger Bundle to Symfony.

```bash
symfony composer require messenger
```

### Controller Method

First, let's create a controller method for the API that accepts likely time-consuming operations. In this case, the endpoint is `request`.

```php
    /**
     * Async Request
     *
     * @Route("/request", name="async_request")
     * @param Request             $request
     * @param MessageBusInterface $bus
     * @return JsonResponse
     */
    public function requestAction(Request $request, MessageBusInterface $bus)
    {
        $type = $request->request->get('type');
        // create notification object
        $notification = new RequestNotification($type);
        // Ask bus to handle it
        $bus->dispatch($notification);
        // return message id
        return new JsonResponse([
            'messageId' => $notification->getMessageId(),
            'success' => true
        ]);
    }
```

- It injects `MessageBusInterface`.
- Creates a notification object (see below) that takes the value from the POST parameter of the request and sets it
- Pass it to the `dispatch` method of `MessageBus`.
    - The message will now be sent to the bus.
- Send a message and we'll get back to you.
    - Returns `messageId` so that the client can identify the message.

### Message object

The class of the message object being instantiated by the controller looks something like this The message is a data object class with no logic. There is no such thing as a message object, but since it is serialized and stored in a queue, it should have only simple data that can be serialized.
  
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
        // create message id
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

- Creates a `messageId` in the constructor.
- This will be the ID that identifies the message

### Message Handler

The role of the message handler is to read the `dispatch`ed message and execute it.

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
        // Write a file with 'in_progress' status
        $this->writeContents($id, $data);
        // Making it slow to process
        sleep(10);
        // Change status to 'done'
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

- Implement the `MessageHandlerInterface` interface.
    - Nothing is defined for this interface, but the Symfony DI can tell that it is a message handler by looking at it
- Write the actual operation to the `__invoke` method.
    - A message is passed to the argument
    - The type hint in the argument determines that this handler is the one associated with the `RequestNotification` message.
    - As a simple process, we are running a job of writing something to a file
- When the first API is called and `dispatch`, this handler will be executed and the file will be written out
- To make the process more time-consuming, we put a `sleep` in the middle.

### Introducing RabbitMQ

At this stage, the transport is not set up, so it will be handled in sync. This means you will have to wait 10 seconds for a response. If you ask for a heavy job, the time to response will take until the job is finished.
This time we will use [RabbitMQ](https://www.rabbitmq.com/) as the transport.

In the development environment, let's run it with docker.

```
version: "3"
services:
  rabbitmq:
    image: rabbitmq:3.7-management
    ports: [5672, 15672]
```

The above is the content of *docker-compose.yaml*. Start with `docker-composer up`.

- **Note**: It will not work if the ampq extension is not installed in PHP. Also, to include the ampq extension, you need to have a local RabbitMQ. To insert an extension in phpbrew, do the following

```bash
brew install rabbitmq-c
phpbrew ext install amqp
```

Define `transport` and `routing` in *messenger.yaml* in *config/packages*.

```yaml
framework:
    messenger:
        transports:
            async: '%env(RABBITMQ_DSN)%'
        routing:
            'App\Message\RequestNotification': async
```

- The `transport` sets the `async` transport to RabbitMQ.
- With `routing`, the `RequestNotification` class is bound to `async`.
- Now, the `RequestNotification` message is executed in RabbitMQ
- If there are other notifications, bind the notification class and transport with `routing`.

### Run `messenger:consume` command

You must execute the `messenger:consume` command in order to run in the background. It's a background operation, so you need to run a different process.

```bash
symfony console messenger:consume -vv
```

- `-vv` is an option to show the log in detail.

If you call the API now, you should get a response right away.
If you look at the *var* directory on the server, you'll see a file has been created. After 10 seconds, it changes from `"state": "in_progress"` immediately after the call to `"state": "done"`.

#### Production environment

In the production environment, if you execute a command normally, it may die or cause a memory leak for some reason.  So we use [Supervisor](http://supervisord.org/) to set up reboots on failure, regular reboots, etc.  Please refer to the [official guide](https://symfony.com/doc/current/messenger.html#supervisor-configuration) for more information. 

### Implementing the API to get the state

It's nice to be able to run a job in the background, but there has to be a way to know if the job is done or not.
Let's write an API like this so that we can check the content
You can call this API to find out the status of a job. If necessary, you can display a progress bar on the client side if you want to return the progress status as well.

```php
    /**
     * Get job status
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
        // get and return the contents of the file created asynchronously
        $content = file_get_contents($fileName);
        $result = json_decode($content);
        return new JsonResponse($result);
    }
```

## Step2: Notification from the server by the Mercure component

Thanks to the Messenger component, we were able to achieve asynchronous processing. Once you've implemented this much, you can also use polling to see what's going on. It is practical enough. Next, let's use SSE to enable server-side notifications, using the [Mercure component](https://symfony.com/doc/current/components/mercure.html).

Start a [Mercure](https://mercure.rocks/) hub and send the SSE to the client by POSTing from the application to that hub.
On the client side, the server event is listened to using [`EventSource`](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events).


### Installing Mercure Components

Install the Symfony Mercure component.

```bash
composer require mercure
```

### Setup Mercure

There's an official Mercure Docker image, so you can launch it.
Add a definition of marcure to *docker-compose.yaml*.

```yaml
version: "3"
services:
  rabbitmq:
    image: rabbitmq:3.7-management
    ports: [5672, 15672]
  mercure:
    image: dunglas/mercure
    environment:
      # This will be the key you decide.
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

#### Get JWT_TOKEN and set it to the environment variable

Open the [sample JWT link](https://jwt.io/#debugger-io?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.iHLdpAEjX4BqCsHJEegxRmO-Y6sMxXwNATrQyRNt3GY) in [Mercure's official guide](https://symfony.com/doc/current/mercure.html) and enter the key you set in `JWT_KEY` in *docker-compose.yaml* above in the field marked *"your-256-bit-secret"*. 
Then, the "Encoded" column is updated, and it becomes `JWT_TOKEN`.

Define the following environment variables in *.env.local*

```
MERCURE_PUBLISH_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_TOKEN='JWT_TOKEN generated by the above'
```

If you restart `docker-compose`, the Mercure hub will be started.

### Sending Notifications

Sending a notification is called a `publish`. Here is the sample code from the official guide

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

- It injects the `PublisherInterface`.
- The injected `publisher` is used to send update notifications.
    - The argument passes an instance of the class `SymfonyComponent\frz}MercureUpdate}.
    - The first argument of `Update` is *topic*.
    - This *topic* must be an IRI (Internationalized Resource Identifier, RFC 3987).
    - A unique identifier for the resource to be dispatched
- `PublisherInterface` を注入しています

#### The `RequestNotification` class has been changed.

To make *topic* an IRI, pass a URL to the message. To do so, the class `RequestNotification` is slightly modified.

In fact, *topic* works normally even if it is not IRI. However, I'm going to follow the rule that this must be an IRI. We want to send a useful URL, so we'll make it pass the URL of the `read` API.


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
        // create message id
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

- If a URL is passed to the constructor, a topic is created based on the URL and `messageId`.

#### Modify `requestAction` controller method

Modify the `requestAction` controller method, as the `RequestNotification` class now accepts URLs.

```php
    /**
     * Async Request
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
        // create notification object
        $notification = new RequestNotification($type, $url);
        // Ask bus to handle it
        $bus->dispatch($notification);
        // return message id
        return new JsonResponse([
            'messageId' => $notification->getMessageId(),
            'topic'     => $notification->getTopic(),
            'success'   => true
        ]);
    }
```

 - Pass the URL to the `RequestNotification`.
   - In accordance with the previous policy, the `url` of the `RequestNotification` is the URL of the `read` request.
 - The response should be returned as `topic`.
   - The client uses this `topic` to subscribe to notifications.
 
#### Modify the *RequestHandler* class.

Modify the `RequestHandler` class to send a notification to the client.

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
        // inject publisher for push notification
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
        // Write a file with 'in_progress' status
        $this->writeContents($id, $data);
        // Making it slow to process
        sleep(10);
        // Change status to 'done'
        $data['state'] = 'done';
        $this->writeContents($id, $data);
        // send push notification
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

- Injecting the `PublisherInterface` in the constructor.
- This injected `publisher` is used to send update notifications.
    - The first argument to `Update` is *topic*, so take the topic from `RequestNotification` and set it
    - The second argument is a set of data to send to the client

### Receiving notifications (client-side JavaScript)

You can use [`EventSource`](https://developer.mozilla.org/ja/docs/Web/API/Server-sent_events/Using_server-sent_events) to receive notifications sent by the server using SSE.
As you can see from the Browser compatibility on Mdn's page, as promised, it doesn't work in IE and older Edge. But don't worry, there is a [polyfill](https://github.com/Yaffle/EventSource).

The client side program is written by Sencha [Sencha Ext JS](https://www.sencha.com/products/extjs/).

#### *Main.js*

The class of view.

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

- Place the Requesst button on the toolbar
- A grid is placed in the center of the screen
    - Display the status of the request in this grid

#### *MainController.js*

This is a ViewController. This will handle the event.

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

- `onRequestButton` is the process when the Request button is clicked.
    - Calling the ViewModel's `sendRequest` method (see below).
- `renderStatus` is the process to draw a status column.
    - If the state is 'done', an icon is displayed to indicate that it is loading.

#### *MainModel.js*

This is a ViewModel. This class is responsible for communication and data retention with the server.

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

        // POSTing to asynchronous requests
        // I'll get back to you soon with a response.
        Ext.Ajax.request({
            url   : '/request',
            params: {'type': 1}
        }).then(result => {
            const data = Ext.decode(result.responseText);
            // Create an EventSource to subscribe notifications from the server
            this.subscribe(data.topic);
            // display the result on the grid
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
            // when the server sends a notification
            const data      = Ext.decode(e.data),
                  messageId = data.messageId,
                  record    = store.findRecord('messageId', messageId);

            // update state
            record.set('state', data.state);
            eventSource.close();
        }
    }
});
```

- The `sendRequest` method calls the API.
- If a response is received (processing in `then`)
    - Call the `subscribe` method
    - Adds the content of the request to the grid (or, more correctly, to the grid-tied `store`).
- The `subscribe` method allows you to listen for notifications for the URL of the topic passed to you.
    - Create an instance of EventSource
    - The `onmessage` file describes the process to be executed when a notification is received.
- In the `onmessage`,
    - Fetching a record corresponding to `messageId` and modifying `state`.
    - It then calls the `close` method to terminate the event subscription
    
### Execution screen

This is a capture of the screen that was executed.

![](mercure.gif)

In order to reduce the length of GIF animations, the waiting time for `RequestHandler` is shortened and executed. You can see that it is handled properly by asynchronous processing.

## Summary.

With the Symfony Messenger component and the Mercure component, we were able to achieve asynchronous processing and completion notification. Depending on your development requirements, you may need both of them, or you may not need notification but want asynchronous processing. But now I can deal with both cases.

If no transport is provided, it will be run synchronously.
So any tasks that may need to be made asynchronous should be handled through messages.
That way, when you need asynchronous, you just define the transport.
It may have a bit of overhead, but it's scalable. Also, from a program structure point of view, it might be nice to naturally separate the receipt of a request from the execution of a request.


## URLs of the reference materials

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

Translated by DeepL