// Import this FIRST: it registers the happy-dom globals a headless `new Editor(...)` needs to build
// a ProseMirror view, so any module that touches the DOM at load time has to come after.
import { GlobalRegistrator } from '@happy-dom/global-registrator';

GlobalRegistrator.register();
