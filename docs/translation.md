# Machine translation

Powers **"See translation"** on SBO Network messages. Two members who do not
share a language can hold one conversation, and neither has to leave the page.

Interface text is *not* machine translated — that comes from `lang/*.json` and
is written by hand. This is only for text a member typed.

## The provider

LibreTranslate, self-hosted. Chosen because a private conversation between two
businesses never leaves infrastructure you control, nothing is billed per
character, and it works in every browser — an on-device browser translator does
not, so Safari and Firefox users would silently lose the feature.

Until it is configured, `Translator` resolves to `NullTranslator`, the endpoint
refuses, and the chat hides the control. Nothing is called and nothing breaks.

## Running it

### With Docker

```
docker run -d --name libretranslate -p 5050:5000 \
  libretranslate/libretranslate --load-only en,fr,es
```

### Without Docker (macOS, how this machine runs it)

Homebrew's Python is unusable on macOS 26 — its `pyexpat` links against a
system `libexpat` it no longer matches, so `ensurepip` dies. `uv` sidesteps it
by downloading a self-contained CPython.

```
brew install uv
mkdir -p ~/.libretranslate && cd ~/.libretranslate
uv venv --python 3.11 venv
VIRTUAL_ENV=~/.libretranslate/venv uv pip install libretranslate
```

`--load-only en,fr,es` installs `en→fr` and `en→es` and stops there. The
reverse directions are what the chat actually needs — a French message shown to
an English reader — so install them explicitly:

```
./venv/bin/argospm install translate-fr_en
./venv/bin/argospm install translate-es_en
```

Then run it:

```
./venv/bin/libretranslate --host 127.0.0.1 --port 5050 --load-only en,fr,es
```

`/languages` should list all three with all three as `targets`. If `fr` only
targets `fr`, the reverse model is missing.

### On the server (AlmaLinux, no Docker, no root)

The production host has neither Docker nor a sudo password for `deploy`, so it
runs from a virtualenv in that user's home. Python 3.9 is what AlmaLinux 9
ships and LibreTranslate 1.9.6 installs against it cleanly.

```
mkdir -p ~/.libretranslate && cd ~/.libretranslate
python3 -m venv venv
./venv/bin/pip install --upgrade pip setuptools wheel
./venv/bin/pip install libretranslate
```

`pip` warns that `wheel` wants `packaging>=24` while LibreTranslate pins 23.1.
Ignore it — `wheel` is only used to build, and nothing at runtime reads it.

The models are the slow part, about 550MB across the four directions. Install
them explicitly rather than letting `--load-only` do it: that flag fetches
`en→fr` and `en→es` only, and the reverse pairs are the ones the chat needs.

```
./venv/bin/argospm update
for m in translate-en_fr translate-fr_en translate-en_es translate-es_en; do
  ./venv/bin/argospm install $m
done
```

`/languages` must show all three languages with all three as `targets`. If `fr`
targets only `fr`, a reverse model is missing.

### Keeping it up

A LaunchAgent at `~/Library/LaunchAgents/com.loveworld.libretranslate.plist`
starts it at login and restarts it if it dies.

```
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.loveworld.libretranslate.plist   # load
launchctl bootout   gui/$(id -u)/com.loveworld.libretranslate                                # unload
```

Logs go to `~/.libretranslate/server.log`.

On the server it is cron, not systemd — a unit in `/etc/systemd/system` needs
root, and `deploy` has no sudo password. `~/.libretranslate/start.sh` starts the
process only if `/languages` does not already answer, which makes one entry do
both jobs:

```
@reboot /home/deploy/.libretranslate/start.sh
*/5 * * * * /home/deploy/.libretranslate/start.sh
```

The five-minute line is the part that matters. `@reboot` alone would leave
translation quietly off until somebody noticed, and the failure is invisible
from the outside: the chat hides "See translation" rather than showing an
error, so nobody reports it. Worst case here is five minutes of the control
being absent.

If root ever becomes available, a systemd unit is better — it restarts on the
crash rather than on the next tick, and `systemctl status` says what happened.

## Port 5050

Not LibreTranslate's own default of 5000. macOS AirPlay Receiver listens on
5000 and answers every request with an empty `403`, which looks exactly like a
broken translator rather than the wrong process. Either move the port or turn
AirPlay Receiver off in System Settings → General → AirDrop & Handoff.

## Configuration

```
TRANSLATION_DRIVER=libretranslate
LIBRETRANSLATE_URL=http://127.0.0.1:5050
LIBRETRANSLATE_KEY=            # only if started with --api-keys
```

DeepL and Google drivers exist behind the same `Translator` interface if a
hosted provider is ever wanted; see `config/translation.php`.

## What it costs

Translations are cached per message and target language in
`sbo_message_translations`, so a message is sent to the provider once however
many people read it. `TRANSLATION_MAX_CHARACTERS` refuses a runaway paste
rather than truncating it — half a translated message is worse than none.
