// Inspect (AGT-014): runs in a preview page, loaded by the annotator in host-proxy.mjs when the workspace turns it
// on. Hovering highlights elements, a click picks one, and dragging one onto another moves it before or after it, or
// swaps them. Moves are shown with copies (the originals are hidden), so the app's own code (React, Vue) never sees
// its elements moved, and everything is put back when inspecting stops. The workspace gets the picks and moves,
// each with its selector, text and component, to tell the agent. Only the frame's own parent is answered.
(() => {
    if (window.__onedropInspector || window.parent === window) {
        return;
    }

    const INLINE = new Set([
        'SPAN',
        'B',
        'I',
        'EM',
        'STRONG',
        'SMALL',
        'svg',
        'path',
        'g',
        'use',
        'circle',
        'rect',
        'line',
        'polyline',
        'polygon',
    ]);
    const SKIP = new Set(['HTML', 'BODY', 'HEAD', 'SCRIPT', 'STYLE']);
    const COLOR = '#3b82f6';
    const DRAG_PX = 5;

    let origin = '*';
    let active = false;
    let host = null;
    let layer = null;
    let cursorStyle = null;
    let hovered = null;
    let drag = null;
    let frame = 0;
    let nextId = 1;
    /**
     * Picks and moves, in order: { id, kind: 'pick' | 'move', element, target?, position?, shown, changes }. A move's
     * changes (copies added, elements hidden) are put back when it's removed or inspecting stops.
     */
    let items = [];
    /** A copy shown for a move, and the element it stands for. */
    const originals = new WeakMap();

    const post = (message) => {
        try {
            window.parent.postMessage(message, origin);
        } catch {
            // The workspace went away.
        }
    };

    const isOurs = (element) =>
        element === host || (host && host.contains(element));

    /** The element an action is about: skipping the icons and formatting inside it (a button, not its svg). */
    const meaningful = (element) => {
        while (
            element &&
            INLINE.has(element.tagName) &&
            element.parentElement &&
            !SKIP.has(element.parentElement.tagName)
        ) {
            element = element.parentElement;
        }

        return element && !SKIP.has(element.tagName) ? element : null;
    };

    const elementAt = (x, y) => {
        for (const element of document.elementsFromPoint(x, y)) {
            if (isOurs(element) || (drag && drag.ghost === element)) {
                continue;
            }

            return meaningful(element);
        }

        return null;
    };

    const unique = (selector) => {
        try {
            return document.querySelectorAll(selector).length === 1;
        } catch {
            return false;
        }
    };

    /** A short selector that finds just this element, to search the code with. */
    const selectorFor = (element) => {
        const tag = element.tagName.toLowerCase();

        if (element.id && unique('#' + CSS.escape(element.id))) {
            return '#' + CSS.escape(element.id);
        }

        for (const attribute of [
            'data-testid',
            'data-test',
            'name',
            'aria-label',
        ]) {
            const value = element.getAttribute(attribute);

            if (
                value &&
                unique(tag + '[' + attribute + '="' + CSS.escape(value) + '"]')
            ) {
                return tag + '[' + attribute + '="' + value + '"]';
            }
        }

        const path = [];
        let node = element;

        while (
            node &&
            node.parentElement &&
            !SKIP.has(node.tagName) &&
            path.length < 5
        ) {
            let part = node.tagName.toLowerCase();

            if (node.id) {
                path.unshift('#' + CSS.escape(node.id));
                break;
            }

            // Classes that read like names, not utilities (Tailwind's `md:px-4`, `w-[3px]`).
            const classes =
                typeof node.className === 'string'
                    ? node.className
                          .trim()
                          .split(/\s+/)
                          .filter((name) => name && !/[:[\]/]/.test(name))
                          .slice(0, 2)
                    : [];
            part += classes.map((name) => '.' + CSS.escape(name)).join('');

            const same = Array.from(node.parentElement.children).filter(
                (sibling) => sibling.tagName === node.tagName,
            );

            if (same.length > 1) {
                part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')';
            }

            path.unshift(part);

            if (unique(path.join(' > '))) {
                break;
            }

            node = node.parentElement;
        }

        return path.join(' > ');
    };

    const textOf = (element) => {
        const text = (
            element.innerText ||
            element.getAttribute('aria-label') ||
            element.getAttribute('placeholder') ||
            element.getAttribute('alt') ||
            element.getAttribute('title') ||
            element.getAttribute('name') ||
            ''
        )
            .replace(/\s+/g, ' ')
            .trim();

        return text.length > 80 ? text.slice(0, 79) + '…' : text;
    };

    /** A path in the app from a dev server's URL or a container path: /workspace/src/App.tsx?t=1 → src/App.tsx. */
    const appPath = (path) => {
        if (typeof path !== 'string' || !path) {
            return null;
        }

        try {
            path = new URL(path, location.href).pathname;
        } catch {
            // A plain path.
        }

        path = path
            .replace(/^\/@fs/, '')
            .replace(/^\/workspace\//, '')
            .replace(/^\//, '');

        return /node_modules|^@vite|^@react-refresh/.test(path) ? null : path;
    };

    /** The source file a React 19 element was written in, from the stack of its creation (dev builds only). */
    const fileFromStack = (stack) => {
        if (!stack) {
            return null;
        }

        for (const match of String(stack.stack || stack).matchAll(
            /(https?:\/\/[^\s)]+?):\d+:\d+/g,
        )) {
            const path = appPath(match[1]);

            if (path) {
                return path;
            }
        }

        return null;
    };

    /** The component that rendered the element and where, for React and Vue apps in development. */
    const componentOf = (element) => {
        const fiberKey = Object.keys(element).find(
            (key) =>
                key.startsWith('__reactFiber$') ||
                key.startsWith('__reactInternalInstance$'),
        );

        if (fiberKey) {
            const fiber = element[fiberKey];
            let owner = fiber._debugOwner;

            if (
                !owner ||
                (typeof owner.type !== 'function' &&
                    typeof owner.type !== 'object')
            ) {
                owner = fiber.return;

                while (
                    owner &&
                    typeof owner.type !== 'function' &&
                    !(owner.type && typeof owner.type === 'object')
                ) {
                    owner = owner.return;
                }
            }

            const type =
                owner &&
                owner.type &&
                (owner.type.render || owner.type.type || owner.type);
            const name =
                (owner &&
                    ((owner.type && owner.type.displayName) ||
                        (type && (type.displayName || type.name)))) ||
                null;
            const source = fiber._debugSource
                ? appPath(fiber._debugSource.fileName) +
                  (fiber._debugSource.lineNumber
                      ? ':' + fiber._debugSource.lineNumber
                      : '')
                : fileFromStack(fiber._debugStack);

            return name || source
                ? {
                      name: name || null,
                      source:
                          source && !source.startsWith('null') ? source : null,
                  }
                : null;
        }

        const vue = element.__vueParentComponent;

        if (vue && vue.type) {
            return {
                name: vue.type.__name || vue.type.name || null,
                source: appPath(vue.type.__file),
            };
        }

        return null;
    };

    const describe = (element) => {
        element = originals.get(element) || element;
        const component = componentOf(element);

        return {
            selector: selectorFor(element),
            tag: element.tagName.toLowerCase(),
            text: textOf(element),
            component: component && component.name,
            source: component && component.source,
        };
    };

    const label = (element) => {
        const about = describe(element);

        return (
            about.selector + (about.component ? ' · ' + about.component : '')
        );
    };

    // Drawing: outlines in a shadow root on top of the page, so the app's styles can't touch them.
    const box = (rect, color, dashed) => {
        const div = document.createElement('div');
        div.style.cssText =
            'position:fixed;pointer-events:none;box-sizing:border-box;border-radius:3px;' +
            'left:' +
            rect.left +
            'px;top:' +
            rect.top +
            'px;width:' +
            rect.width +
            'px;height:' +
            rect.height +
            'px;' +
            'border:2px ' +
            (dashed ? 'dashed ' : 'solid ') +
            color +
            ';background:' +
            color +
            '1a';

        return div;
    };

    const tag = (text, rect, color) => {
        const span = document.createElement('span');
        span.textContent = text;
        span.style.cssText =
            'position:fixed;pointer-events:none;font:600 11px/1.6 ui-sans-serif,system-ui,sans-serif;' +
            'color:#fff;background:' +
            color +
            ';padding:0 6px;border-radius:3px;white-space:nowrap;max-width:60vw;' +
            'overflow:hidden;text-overflow:ellipsis;left:' +
            Math.max(0, rect.left) +
            'px;' +
            'top:' +
            (rect.top > 20 ? rect.top - 19 : rect.bottom + 2) +
            'px';

        return span;
    };

    const draw = () => {
        frame = 0;

        if (!layer) {
            return;
        }

        layer.replaceChildren();
        items.forEach((item, index) => {
            const rect = item.shown.getBoundingClientRect();
            layer.append(box(rect, COLOR), tag(String(index + 1), rect, COLOR));
        });

        if (drag && drag.started) {
            if (drag.target) {
                const rect = drag.target.getBoundingClientRect();

                if (drag.position === 'swap') {
                    layer.append(
                        box(rect, '#22c55e', true),
                        tag('Swap', rect, '#22c55e'),
                    );
                } else {
                    const line = document.createElement('div');
                    const before = drag.position === 'before';
                    line.style.cssText =
                        'position:fixed;pointer-events:none;background:#22c55e;border-radius:2px;' +
                        (drag.horizontal
                            ? 'top:' +
                              rect.top +
                              'px;height:' +
                              rect.height +
                              'px;width:4px;left:' +
                              ((before ? rect.left : rect.right) - 2) +
                              'px'
                            : 'left:' +
                              rect.left +
                              'px;width:' +
                              rect.width +
                              'px;height:4px;top:' +
                              ((before ? rect.top : rect.bottom) - 2) +
                              'px');
                    layer.append(
                        line,
                        tag(
                            before ? 'Move before' : 'Move after',
                            rect,
                            '#22c55e',
                        ),
                    );
                }
            }
        } else if (hovered) {
            const rect = hovered.getBoundingClientRect();
            layer.append(
                box(rect, '#f97316', true),
                tag(label(hovered), rect, '#f97316'),
            );
        }
    };

    const redraw = () => {
        if (!frame) {
            frame = requestAnimationFrame(draw);
        }
    };

    const report = () => {
        post({
            onedrop: 'inspector',
            items: items.map((item) => ({
                id: item.id,
                kind: item.kind,
                element: item.element,
                target: item.target,
                position: item.position,
            })),
        });
        redraw();
    };

    /** The element under the pointer that a dragged one would be dropped on, and where: before, after or swap. */
    const dropAt = (x, y) => {
        const dragged = drag.element;
        let target = elementAt(x, y);

        if (!target || target === dragged || dragged.contains(target)) {
            return null;
        }

        // Dropping on the only thing inside a wrapper means the wrapper (a card, not the text in it).
        while (
            target.parentElement &&
            target.parentElement !== document.body &&
            target.parentElement.children.length === 1 &&
            !target.parentElement.contains(dragged)
        ) {
            target = target.parentElement;
        }

        if (target.contains(dragged)) {
            return null;
        }

        const rect = target.getBoundingClientRect();
        const parent = target.parentElement
            ? getComputedStyle(target.parentElement)
            : null;
        const display = getComputedStyle(target).display;
        const horizontal =
            (parent &&
                /flex/.test(parent.display) &&
                !/column/.test(parent.flexDirection)) ||
            (parent &&
                parent.display === 'grid' &&
                rect.width <
                    target.parentElement.getBoundingClientRect().width * 0.9) ||
            display.startsWith('inline');
        const along = horizontal
            ? (x - rect.left) / rect.width
            : (y - rect.top) / rect.height;

        return {
            target,
            horizontal,
            position: along < 0.3 ? 'before' : along > 0.7 ? 'after' : 'swap',
        };
    };

    const hide = (element, changes) => {
        changes.push({
            element,
            display: element.style.getPropertyValue('display'),
            priority: element.style.getPropertyPriority('display'),
        });
        element.style.setProperty('display', 'none', 'important');
    };

    /** A copy of an element where it was moved to; the original is hidden, so the app's code doesn't notice. */
    const copyAt = (element, marker, changes) => {
        const copy = element.cloneNode(true);
        copy.removeAttribute('id');
        originals.set(copy, originals.get(element) || element);
        marker.parentNode.insertBefore(copy, marker);
        changes.push({ copy });

        return copy;
    };

    const revert = (changes) => {
        for (const change of changes.slice().reverse()) {
            if (change.copy) {
                change.copy.remove();
                // Picks of the copy go back to the element it stood for.
                items.forEach((item) => {
                    if (item.shown === change.copy) {
                        item.shown = originals.get(change.copy);
                    }
                });
            } else if (change.display) {
                change.element.style.setProperty(
                    'display',
                    change.display,
                    change.priority,
                );
            } else {
                change.element.style.removeProperty('display');
            }
        }
    };

    const move = (dragged, target, position) => {
        const changes = [];
        // Markers keep where both were, so swapping neighbours comes out the right way round.
        const atDragged = document.createComment('');
        const atTarget = document.createComment('');
        dragged.parentNode.insertBefore(atDragged, dragged);
        target.parentNode.insertBefore(
            atTarget,
            position === 'after' ? target.nextSibling : target,
        );

        const shown = copyAt(dragged, atTarget, changes);

        if (position === 'swap') {
            copyAt(target, atDragged, changes);
            hide(target, changes);
        }

        hide(dragged, changes);
        atDragged.remove();
        atTarget.remove();
        // A pick of an element that just moved follows its copy.
        items.forEach((item) => {
            if (item.shown === dragged) {
                item.shown = shown;
            }
        });
        items.push({
            id: nextId++,
            kind: 'move',
            element: describe(dragged),
            target: describe(target),
            position,
            shown,
            changes,
        });
    };

    const togglePick = (element) => {
        const existing = items.findIndex(
            (item) => item.kind === 'pick' && item.shown === element,
        );

        if (existing !== -1) {
            items.splice(existing, 1);
        } else {
            items.push({
                id: nextId++,
                kind: 'pick',
                element: describe(element),
                shown: element,
                changes: [],
            });
        }

        report();
    };

    const swallow = (event) => {
        if (!isOurs(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };

    const onPointerDown = (event) => {
        swallow(event);

        if (event.button === 0) {
            const element = elementAt(event.clientX, event.clientY);
            drag = element
                ? {
                      element,
                      x: event.clientX,
                      y: event.clientY,
                      started: false,
                  }
                : null;
        }
    };

    const onPointerMove = (event) => {
        pointer = { x: event.clientX, y: event.clientY };

        if (drag) {
            if (
                !drag.started &&
                Math.hypot(event.clientX - drag.x, event.clientY - drag.y) >
                    DRAG_PX
            ) {
                const rect = drag.element.getBoundingClientRect();
                drag.started = true;
                drag.offset = {
                    x: event.clientX - rect.left,
                    y: event.clientY - rect.top,
                };
                drag.ghost = drag.element.cloneNode(true);
                drag.ghost.removeAttribute('id');
                drag.ghost.style.cssText +=
                    ';position:fixed;pointer-events:none;opacity:0.75;z-index:2147483646;margin:0;' +
                    'width:' +
                    rect.width +
                    'px;height:' +
                    rect.height +
                    'px;box-shadow:0 8px 24px rgba(0,0,0,.25)';
                document.documentElement.appendChild(drag.ghost);
            }

            if (drag.started) {
                drag.ghost.style.left = event.clientX - drag.offset.x + 'px';
                drag.ghost.style.top = event.clientY - drag.offset.y + 'px';
                Object.assign(
                    drag,
                    { target: null, position: null },
                    dropAt(event.clientX, event.clientY) || {},
                );
            }
        } else {
            hovered = elementAt(event.clientX, event.clientY);
        }

        redraw();
    };

    const onPointerUp = (event) => {
        swallow(event);
        const done = drag;
        drag = null;

        if (!done) {
            return;
        }

        if (done.ghost) {
            done.ghost.remove();
        }

        if (!done.started) {
            togglePick(done.element);
        } else if (done.target) {
            move(done.element, done.target, done.position);
            report();
        }

        hovered = elementAt(event.clientX, event.clientY);
        redraw();
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            post({ onedrop: 'inspector-exit' });
        }
    };

    const LISTENERS = [
        ['pointerdown', onPointerDown],
        ['pointermove', onPointerMove],
        ['pointerup', onPointerUp],
        ['click', swallow],
        ['dblclick', swallow],
        ['mousedown', swallow],
        ['mouseup', swallow],
        ['contextmenu', swallow],
        ['dragstart', swallow],
        ['submit', swallow],
        ['keydown', onKeyDown],
        [
            'mouseout',
            (event) => {
                if (!event.relatedTarget) {
                    hovered = null;
                    redraw();
                }
            },
        ],
        ['scroll', redraw],
        ['resize', redraw],
    ];

    const start = () => {
        if (active) {
            return;
        }

        active = true;
        host = document.createElement('onedrop-inspector');
        host.style.cssText =
            'position:fixed;inset:0;pointer-events:none;z-index:2147483647';
        layer = document.createElement('div');
        host.attachShadow({ mode: 'open' }).append(layer);
        document.documentElement.appendChild(host);
        cursorStyle = document.createElement('style');
        cursorStyle.textContent =
            '*{cursor:crosshair!important;user-select:none!important}';
        (document.head || document.documentElement).appendChild(cursorStyle);
        LISTENERS.forEach(([type, listener]) =>
            window.addEventListener(type, listener, true),
        );
        report();
    };

    /** Stop inspecting, and put the page back as it was. */
    const stop = () => {
        if (!active) {
            return;
        }

        active = false;
        LISTENERS.forEach(([type, listener]) =>
            window.removeEventListener(type, listener, true),
        );

        items
            .slice()
            .reverse()
            .forEach((item) => revert(item.changes));

        if (drag && drag.ghost) {
            drag.ghost.remove();
        }

        host.remove();
        cursorStyle.remove();
        host = layer = cursorStyle = hovered = drag = null;
        items = [];
    };

    const handle = (data, from) => {
        if (!data || typeof data !== 'object') {
            return;
        }

        if (data.onedrop === 'inspector-start') {
            origin = from === 'null' ? '*' : from;
            start();
        } else if (data.onedrop === 'inspector-stop') {
            stop();
        } else if (!active) {
            return;
        } else if (data.onedrop === 'inspector-remove') {
            const item = items.find((candidate) => candidate.id === data.item);

            if (item) {
                revert(item.changes);
                items = items.filter((candidate) => candidate !== item);
                report();
            }
        } else if (data.onedrop === 'inspector-parent') {
            const item = items.find(
                (candidate) =>
                    candidate.id === data.item && candidate.kind === 'pick',
            );
            const parent = item && meaningful(item.shown.parentElement);

            if (item && parent) {
                item.shown = parent;
                item.element = describe(parent);
                report();
            }
        } else if (data.onedrop === 'inspector-rects') {
            post({
                onedrop: 'inspector-rects',
                id: data.id,
                rects: items.map((item) => {
                    const rect = item.shown.getBoundingClientRect();

                    return {
                        id: item.id,
                        x: rect.left,
                        y: rect.top,
                        width: rect.width,
                        height: rect.height,
                    };
                }),
            });
        }
    };

    window.__onedropInspector = {
        handle,
        /** Hide the outlines while the page takes a picture of itself; the workspace draws its own. */
        hide: () => host && (host.style.display = 'none'),
        show: () => host && (host.style.display = ''),
    };

    window.addEventListener('message', (event) => {
        if (event.source === window.parent) {
            handle(event.data, event.origin);
        }
    });
})();
