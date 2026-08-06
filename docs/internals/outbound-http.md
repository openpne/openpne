# Outbound HTTP

Some features need to dereference a URL a member typed. Doing that turns the server into a
request forwarder for anyone who can post, so every such fetch goes through one seam:
[`App\Outbound`](../../app/Outbound). Nothing else in `app/` may open an outbound connection, and
[`OutboundEgressBoundaryTest`](../../tests/Feature/Architecture/OutboundEgressBoundaryTest.php)
fails the build if something does.

The boundary is the point. SSRF defence is not a property of any one call site — it is the property
that *every* fetch went through the guard, so a single unremarkable `Http::get($url)` added later
undoes it no matter how careful this code is. The forbidden set in that test is wider than the HTTP
client (stream wrappers, raw sockets) because those are what someone reaches for when the obvious
call is unavailable.

## What the guard does

[`SafeHttpFetcher`](../../app/Outbound/SafeHttpFetcher.php) is the only entry point.

1. **Validate the URL.** `http`/`https` only, port 80 or 443 only, no userinfo, bounded length. The
   host is canonicalised — trailing dot removed, IDN converted to Punycode — and the request URL is
   *rewritten* to carry that canonical host.
2. **Resolve and judge every address.** [`PublicIpGuard`](../../app/Outbound/PublicIpGuard.php)
   must accept **all** A and AAAA answers, not just the one dialled: a name answering with a public
   and a private address is answering with a private address, and which one arrives first is the
   answerer's choice. An empty answer fails. An address literal skips resolution and is judged
   directly.
3. **Pin the connection.** `CURLOPT_CONNECT_TO` sends the request to a validated address while
   leaving the `Host` header and TLS SNI as the URL's own host. This closes the window between
   checking DNS and dialling, in which a second answer can point somewhere else.
4. **Re-enter for every redirect.** libcurl does not follow them. Each `Location` is resolved
   against the URL that issued it and goes through steps 1–3 from the top, so hop two is guarded
   exactly as hop one.
5. **Verify where it landed.** The peer address is read back from the transfer and must be one of
   the validated ones. The pin is a libcurl feature reached through two layers of configuration and
   its failure mode is silence — the request just succeeds, aimed wherever DNS pointed. This turns
   that into a failed fetch.

Requests carry no cookies, no credentials (`CURLOPT_NETRC` ignored) and no `Referer`, and TLS
verification is not configurable off.

### IPv6 addresses that embed an IPv4 one

IPv4-mapped, IPv4-compatible, 6to4 and the NAT64 **well-known** prefix (`64:ff9b::/96`) are not
judged on their wrapper — the embedded address is extracted and re-checked, because that is where
the packet ends up. `64:ff9b:1::/48` is refused outright: RFC 6052 allows the embedded address at
several offsets there, so there is no single position to read.

A NAT64 **network-specific** prefix is any global prefix the operator chose, so it cannot be
recognised from the address alone. An install behind one must list it in `outbound.denied_cidrs`.

## Why there is no proxy setting

An HTTP or SOCKS proxy resolves the destination host itself, which is the exact step the pin exists
to control — configuring one silently reverts the guard to trusting DNS. Supporting a proxy means
making the proxy a trusted enforcement point, a different contract than a config value. So there is
no setting, and the environment variables libcurl would otherwise honour are disabled explicitly
(`CURLOPT_PROXY`, `CURLOPT_NOPROXY`).

## Why ext-curl is required

`composer.json` requires `ext-curl`, and the client is built on an explicit `CurlHandler`
([`CurlClientFactory`](../../app/Outbound/CurlClientFactory.php)). Without the extension Guzzle
falls back to the PHP stream handler, where **every `CURLOPT_*` is ignored while requests keep
working** — the guard would run, the pin would not, and nothing would say so.
[`OutboundTransportTest`](../../tests/Feature/Outbound/OutboundTransportTest.php) pins the
extension, the libcurl version (`CURLOPT_CONNECT_TO` needs 7.49+) and the handler choice, because
the unit tests drive a fake handler where curl options are inert data.

## Size and time limits

Read caps are on **decoded** bytes ([`CappedStream`](../../app/Outbound/CappedStream.php)): libcurl
inflates a gzip response before the write callback sees it, so a `Content-Length` check would let a
small compressed body expand without bound. Past the cap the sink returns a short write, which is
libcurl's signal to abort the transfer rather than download the rest. The bytes already collected
are kept — for HTML they are the useful ones, since `<head>` comes first.

Three deadlines nest, all in `config/openpne.php` under `outbound`: one request, one fetch
(a request plus its redirects), and a whole job. Without the outer two a chain of individually-legal
slow hops adds up to an unbounded job.

## Key invariants

- `App\Outbound` is the only directory in `app/` that opens a connection, enforced by test.
- No URL is dialled without having passed `SafeHttpFetcher::validate()` — including redirect targets.
- The address validated is the address connected to, verified after the fact rather than assumed.
- `PublicIpGuard` is derived from IANA's special-purpose registries and re-judges any embedded IPv4
  (IPv4-mapped, 6to4, NAT64) on the address the packet actually reaches. `denied_cidrs` can only
  subtract from what is reachable; nothing in config can re-permit a rejected address.
- Byte caps count decoded bytes, not `Content-Length`.
