// Side-effect module: register happy-dom globals (window, document, DOMParser) so a headless
// `new Editor(...)` can build a ProseMirror view under `node --test`. Import this FIRST, before any
// module that touches the DOM at load time.
import { GlobalRegistrator } from '@happy-dom/global-registrator';

GlobalRegistrator.register();
