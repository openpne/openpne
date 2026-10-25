# Attachments

A post's slot rows — `diary_images`, `group_topic_images`, `group_message_images`,
`direct_message_files` and the rest — hold every file a member attached, picture or not. Pictures
and other files share the same slots, the same per-post count (`PostImages::MAX_IMAGES`) and the
same owner (the post), so [`FilePolicy`](../../app/Policies/FilePolicy.php) needs no arm of its own
for a file: whoever may read the post may download what hangs on it.

## Pictures and files

Which kind a slot row is comes from the stored type alone: [`ImageSpec::rasterMimes()`](../../app/Files/ImageSpec.php)
is a picture, anything else a file. Two readers exist for that split and no third:

- `pictures()` on every slot-bearing model ([`PostAttachments::pictures`](../../app/Files/PostAttachments.php))
  is the relation to count or test in SQL — `withCount('pictures')`, `pictures_exists`,
  `whereHas('pictures')` — wherever a list means "has photos".
- `PostAttachments::partition()` splits loaded slot rows for display; every serializer that ships an
  `images` array ships a `files` array from the same call, each file in the
  [`AttachmentShape`](../../app/Files/AttachmentShape.php) (`id`, `url`, `name`, `byteSize`,
  `sizeLabel`, `type`). `images()` itself stays the whole set, read by the editors and the delete
  actions. `PictureReadersTest` keeps the slot relation out of counts and existence checks.

A message with files and no pictures previews as "File" where one with pictures previews as
"Image" (`ChatPreview`); a diary with only a file has no photo marker.

## Validation

Every `images[]` entry is a [`PostUpload`](../../app/Rules/PostUpload.php): a type the processor
reads takes the picture rules (`PostImageRules::imageRule()`, the picture cap and the decode
guards); a sidecar-only type the processor cannot read (HEIC under GD) is refused as the picture it
is rather than becoming a download; anything else is a file under
[`AttachmentLimit`](../../app/Files/AttachmentLimit.php), `OPENPNE_FILE_MAX_UPLOAD_KB` (10240 by
default). There is no extension or type allow list: the browser never interprets the stored
bytes (below), an allow list blocks the spreadsheets and archives people share, and a deny list is
undone by renaming. The picture cap stays smaller than the file cap because it bounds a decode, not
only storage. The MCP post tools keep the picture-only rules (`PostImageRules::imagesOnly()`), as
their readers show pictures and nothing else.

## Delivery

A file goes out through [`FileResponse::attachment`](../../app/Files/FileResponse.php): the
stored bytes, `application/octet-stream`, `Content-Disposition: attachment` naming the uploader's
file, `X-Content-Type-Options: nosniff`, whatever the name or sniffed type says
([security](security.md), "Inline delivery is re-encoded"). The Modern surface draws a file as a
chip (`file-chip.tsx`) under the pictures; Classic lists it in `<ul class="files">` beside
`<ul class="photo">`. Neither opens a new tab: the attachment disposition downloads in place.

## Limits

`OPENPNE_FILE_MAX_UPLOAD_KB` is read like the picture cap ([images](images.md), "Upload size"):
PHP's `upload_max_filesize` must be at least the cap and `post_max_size` must hold three of them
with the form, the shipped `docker/` stack is sized for the default (10M / 32M), and shared hosting
with a smaller `upload_max_filesize` refuses the file before validation can name the reason. The
DB-blob backend buffers one file in memory on read and on write, so the cap also bounds that
([file-storage](file-storage.md), "Memory shape").
