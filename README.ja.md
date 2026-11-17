# OpenPNE

[English](README.md)

OpenPNE は、自分たちの SNS サイトを作るためのオープンソースソフトウェアです。地域、サークル、会社、ファンの集まりなど、ひとつのコミュニティのための場所を、自分で管理するサーバに置けます。誰でも参加できるサイトにも、招待制のサイトにもできます。

<p align="center">
  <img src="docs/images/ja/home.webp" width="30%" alt="ホーム: この 1 週間にメンバーが投稿したできごと">
  <img src="docs/images/ja/talk.webp" width="30%" alt="グループのトーク。写真 3 枚のメッセージと、メンバーに答える AI アカウント">
  <img src="docs/images/ja/entry.webp" width="30%" alt="写真、リアクション、コメントの付いた日記">
</p>

画面に出てくる人と投稿は、すべて架空のものです([docs/images](docs/images/README.md))。

このリポジトリは OpenPNE 4 です。[OpenPNE 3](https://github.com/openpne/OpenPNE3) の後継として、Laravel 13 で作り直しました。

この文書は [README.md](README.md) の入口の部分を日本語にしたものです。内容が食い違う場合は、英語版が正です。

## サイトでできること

- **メンバー**: プロフィール、フレンド、メッセージ、通知。
- **日記**: 写真、コメント、リアクションを付けられます。誰に見せるかを 1 件ずつ選べます。
- **グループ**: チャットのための「トーク」、じっくり話すための「トピック」、参加を募る「イベント」。
- 短い投稿のための**タイムライン**と、サイトであったことをまとめて見られる**ホーム**。
- **2 つの見た目**: スマホを基準に作った Modern と、OpenPNE 3 のサイトの画面・URL・テーマを引き継ぐ Classic。
- **管理画面**: メンバー、投稿、登録の受け付け方、サイトの見た目。
- **ログイン**: パスワード、2 段階認証、パスキー。
- **日本語と英語**。メンバーごとに選べます。
- **AI クライアント**が [MCP](https://modelcontextprotocol.io) サーバを通して読み書きできます。メンバーとして、またはメンバーが持つ AI アカウントとして使えます。
- **OpenPNE 3 からの移行**。メンバーと書いたものを引き継ぎます([docs/upgrading-from-openpne3.md](docs/upgrading-from-openpne3.md)、英語)。

## 試す

[Docker](https://docs.docker.com/get-started/get-docker/) と Compose があれば、次のコマンドで、手元で触れるサイトが立ち上がります。

```bash
git clone https://github.com/openpne/openpne.git && cd openpne
bin/dev-up                                                           # http://localhost:8080
docker compose exec app php artisan openpne:install                  # テーブルと、新しいサイトの初期データ
docker compose exec app php artisan openpne:admin:create admin       # パスワードを聞かれます。/admin でログイン
docker compose exec app php artisan openpne:member:invite you@example.com
```

最初の `bin/dev-up` には数分かかります(イメージを作り、依存を入れ、フロントエンドの開発サーバを起こして、サイトが応答するまで待つため)。最後のコマンドが、登録用のリンクを表示します。リンクを開いて名前とパスワードを決めると、あなたがサイトの最初のメンバーです。日記を書いたり、グループを作ったりできます。ほかの人も同じコマンドで招待できます。管理画面の「メンバー招待」からも招待でき、そのメールは実際のメールボックスではなく <http://localhost:8025> に届きます。

この構成は、OpenPNE を試すためと、開発するためのものです。データは手元の SQLite ファイルに入り、フロントエンドは開発サーバから配られます。

## サイトを運営する

人が使うサイトでは、`main` ではなくタグの付いたリリース(`v4.0.0-alpha.N`、[Releases](https://github.com/openpne/openpne/releases))を使います。必要なものは、PHP 8.3 以降、SQLite か MySQL、Web サーバ、スケジューラの設定、キューに入った処理を動かす手段です([docs/internals/runtime.md](docs/internals/runtime.md) の "Queue"、英語)。ビルドには Composer 2 と Node.js 26 を使います。リリースからリリースへの更新は [docs/updating.md](docs/updating.md)(英語)にあります。

バージョンに `-alpha` が付いている間は、互換性の手当てなしに動きや保存データが変わることがあります。修正は新しいリリースとして出し、前の alpha には入りません。

## そのほか

- 開発の手順: [README.md](README.md#developing)(英語)
- OpenPNE 3 からの移行: [docs/upgrading-from-openpne3.md](docs/upgrading-from-openpne3.md)(英語)
- 入れられない・更新できない、不具合の報告: [New issue](https://github.com/openpne/openpne/issues/new/choose) のフォーム(項目は英語、記入は日本語でかまいません)
- 脆弱性の報告: [SECURITY.md](SECURITY.md)(英語)
- ライセンス: Apache License 2.0([LICENSE](LICENSE))
