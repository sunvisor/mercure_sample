# 非同期とpush通知のサンプルプロジェクト

- Symfony の Messenger コンポーネントを使って非同期のリクエストを実現
- Symfony の Mercure コンポーネントを使って、作業の完了をClientに通知
- Ext JS で通知を購読して、画面を更新

## セットアップ

ローカルで開発環境を動かす

### Ext JS のセットアップ

- Sencha Cmd の入手
  - <https://www.sencha.com/products/extjs/cmd-download/>
  - インストール
  - <https://docs.sencha.com/extjs/7.1.0/guides/getting_started/getting_started.html>
- Ext JS の入手
  - <https://www.sencha.com/products/extjs/communityedition/>
  - 適当なディレクトリに展開

```bash
cd /path/to/thisProject/client
sencha app install --frameworks= /path/to/ext-7.1.0.46 # Ext JS を展開したディレクトリ
sencha app build
```

#### リンクの作成

- ビルド済みを使う場合 (速い)

```bash
cd /path/to/thisProject/server/public
ln -s ../../client/build/production/App ./app
```

- ビルド前のを使う場合 (遅いけど修正がすぐ確認できる)

```bash
cd /path/to/thisProject/server/public
ln -s ../../client/ ./app
```

### composer install

```bash
cd /path/to/thisProject/server
composer install
```

### mercure の Hub をインストール

- <https://mercure.rocks/docs/hub/install> に従って Mercure の Hub をインストール

## 起動

### docker の起動

```bash
cd /path/to/thisProject/server
docker-compose up
```

### mercure の起動

- 次のように起動 (Macの場合)

```bash
cd /path/to/mercure
./mercure --jwt-key='!ChangeMe!' --addr=':3000' --debug --allow-anonymous --cors-allowed-origins='*' --publish-allowed-origins='http://localhost:3000'
```

### Messenger の起動

```bash
cd /path/to/thisProject/server
symfony console messenger:consume -vv
```

### PHP サーバーの起動

```bash
symfony server:start
```

## ブラウザーでアクセス

```text
https://localhost:8000/
```
