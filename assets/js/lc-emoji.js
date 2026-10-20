(function (w) {
    function list(text) {
        return text.trim().split(/\s+/);
    }

    var groups = [
        {
            id: 'smile',
            label: 'Smileys',
            icon: '😀',
            items: list('😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🤯 🤠 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👹 👺 👻 👽 👾 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾 🙈 🙉 🙊')
        },
        {
            id: 'people',
            label: 'People',
            icon: '👋',
            items: list('👋 🤚 🖐️ ✋ 🖖 👌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💅 🤳 💪 👂 👃 👀 👅 👄 👶 👧 🧒 👦 👩 🧑 👨 👵 👴 👮 👷 💂 🕵️ 👸 🤴 👳 👲 🧕 🧔 👱 👨 👩 🤰 🤱 👼 🎅 🤶 🦸 🦹 🧙 🧚 🧛 🧜 🧝 🧞 🧟 💆 💇 🚶 🏃 💃 🕺 👯 🧖 🧘 🙇 💁 🙋 🤦 🤷 🙎 🙍')
        },
        {
            id: 'nature',
            label: 'Nature',
            icon: '🐶',
            items: list('🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🐣 🐥 🦆 🦅 🦉 🦇 🐺 🐗 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐜 🦗 🕷️ 🦂 🐢 🐍 🦎 🐙 🦑 🦐 🦞 🦀 🐡 🐠 🐟 🐬 🐳 🐋 🦈 🐊 🐅 🐆 🦓 🦍 🐘 🦏 🐪 🐫 🦒 🐃 🐂 🐄 🐎 🐖 🐏 🐑 🐐 🦌 🐕 🐩 🐈 🐓 🦃 🕊️ 🐇 🐁 🐀 🐿️ 🦔 🐾 🐉 🐲 🌵 🎄 🌲 🌳 🌴 🌱 🌿 ☘️ 🍀 🎍 🎋 🍃 🍂 🍁 🍄 🐚 🌾 💐 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌛 🌜 🌚 🌕 🌖 🌗 🌘 🌑 🌒 🌓 🌔 🌙 🌎 🌍 🌏 💫 ⭐ 🌟 ✨ ⚡ 💥 🔥 🌈 ☀️ 🌤️ ⛅ 🌥️ ☁️ 🌦️ 🌧️ ⛈️ 🌩️ 🌨️ ❄️ ☃️ ⛄ 💨 💧 💦 ☔ ☂️ 🌊')
        },
        {
            id: 'food',
            label: 'Food',
            icon: '🍔',
            items: list('🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍈 🍒 🍑 🍍 🥝 🍅 🍆 🥑 🥦 🥒 🌶️ 🌽 🥕 🥔 🍠 🥐 🍞 🥖 🥨 🧀 🥚 🍳 🥞 🥓 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🌮 🌯 🥗 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🍤 🍙 🍚 🍘 🍥 🍢 🍡 🍧 🍨 🍦 🥧 🍰 🎂 🍮 🍭 🍬 🍫 🍿 🍩 🍪 🌰 🥜 🍯 🥛 🍼 ☕ 🍵 🍶 🍺 🍻 🥂 🍷 🥃 🍸 🍹 🍾 🥄 🍴 🍽️')
        },
        {
            id: 'activity',
            label: 'Activity',
            icon: '⚽',
            items: list('⚽ 🏀 🏈 ⚾ 🎾 🏐 🏉 🎱 🏓 🏸 🏒 🏑 🏏 ⛳ 🏹 🎣 🥊 🥋 🎽 ⛸️ 🎿 ⛷️ 🏂 🏋️ 🤼 🤸 🤺 🤾 🏌️ 🏇 🏄 🏊 🤽 🚣 🧗 🚵 🚴 🏆 🥇 🥈 🥉 🏅 🎖️ 🎫 🎪 🎭 🎨 🎬 🎤 🎧 🎼 🎹 🥁 🎷 🎺 🎸 🎻 🎲 🎯 🎳 🎮 🕹️ 🎰 🧩')
        },
        {
            id: 'travel',
            label: 'Travel',
            icon: '🚗',
            items: list('🚗 🚕 🚙 🚌 🚎 🏎️ 🚓 🚑 🚒 🚐 🚚 🚛 🚜 🛴 🚲 🛵 🏍️ 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚟 🚃 🚋 🚞 🚝 🚄 🚅 🚈 🚂 🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 💺 🚀 🚁 🛶 ⛵ 🚤 🛳️ ⛴️ 🚢 ⚓ ⛽ 🚧 🚦 🚥 🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ ⛱️ 🏖️ 🏝️ 🏜️ 🌋 ⛰️ 🏔️ 🗻 🏕️ ⛺ 🏠 🏡 🏘️ 🏚️ 🏗️ 🏭 🏢 🏬 🏣 🏤 🏥 🏦 🏨 🏪 🏫 🏩 💒 🕌 🕍 ⛪ 🕋 ⛩️ 🛤️ 🛣️ 🗾 🎑 🏞️ 🌅 🌄 🌠 🎇 🎆 🌇 🌆 🏙️ 🌃 🌌 🌉 🌁')
        },
        {
            id: 'objects',
            label: 'Objects',
            icon: '💡',
            items: list('⌚ 📱 📲 💻 ⌨️ 🖥️ 🖨️ 🖱️ 💽 💾 💿 📀 📷 📸 📹 🎥 📞 ☎️ 📟 📠 📺 📻 🎙️ ⏱️ ⏲️ ⏰ 🕰️ ⌛ ⏳ 📡 🔋 🔌 💡 🔦 🕯️ 💸 💵 💴 💶 💷 💰 💳 💎 ⚖️ 🔧 🔨 ⚒️ 🛠️ ⛏️ 🔩 ⚙️ ⛓️ 🔫 💣 🔪 🗡️ ⚔️ 🛡️ 🚬 ⚰️ ⚱️ 🏺 🔮 📿 💈 ⚗️ 🔭 🔬 💊 💉 🧬 🦠 🧫 🧪 🌡️ 🧹 🧺 🧻 🚽 🚰 🚿 🛁 🧼 🧽 🧴 🔑 🗝️ 🚪 🛏️ 🧸 🖼️ 🛍️ 🛒 🎁 🎈 🎀 🎊 🎉 🎎 🏮 🎐 ✉️ 📩 📨 📧 💌 📥 📤 📦 🏷️ 📪 📫 📬 📭 📮 📯 📜 📃 📄 📑 📊 📈 📉 📆 📅 🗑️ 📋 📁 📂 🗞️ 📰 📓 📔 📒 📕 📗 📘 📙 📚 📖 🔖 🔗 📎 🖇️ 📐 📏 📌 📍 ✂️ 🖊️ 🖋️ ✒️ 🖌️ 🖍️ 📝 ✏️ 🔍 🔎 🔏 🔐 🔒 🔓')
        },
        {
            id: 'symbols',
            label: 'Symbols',
            icon: '❤️',
            items: list('❤️ 🧡 💛 💚 💙 💜 🖤 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✝️ ☪️ 🕉️ ☸️ ✡️ 🔯 🕎 ☯️ ☦️ 🛐 ⛎ ♈ ♉ ♊ ♋ ♌ ♍ ♎ ♏ ♐ ♑ ♒ ♓ 🆔 ⚛️ 🉑 ☢️ ☣️ 📴 📳 🈶 🈚 🈸 🈺 🈷️ ✴️ 🆚 💮 🉐 ㊙️ ㊗️ 🈴 🈵 🈹 🈲 🅰️ 🅱️ 🆎 🆑 🅾️ 🆘 ❌ ⭕ 🛑 ⛔ 📛 🚫 💯 💢 ♨️ 🚷 🚯 🚳 🚱 🔞 📵 🚭 ❗ ❕ ❓ ❔ ‼️ ⁉️ 🔅 🔆 〽️ ⚠️ 🚸 🔱 ⚜️ 🔰 ♻️ ✅ 🈯 💹 ❇️ ✳️ ❎ 🌐 💠 Ⓜ️ 🌀 💤 🏧 🚾 ♿ 🅿️ 🈳 🈂️ 🛂 🛃 🛄 🛅 🚹 🚺 🚼 🚻 🚮 🎦 📶 🈁 🔣 ℹ️ 🔤 🔡 🔠 🆖 🆗 🆙 🆒 🆕 🆓 0️⃣ 1️⃣ 2️⃣ 3️⃣ 4️⃣ 5️⃣ 6️⃣ 7️⃣ 8️⃣ 9️⃣ 🔟 ▶️ ⏸️ ⏯️ ⏹️ ⏺️ ⏭️ ⏮️ ⏩ ⏪ ⏫ ⏬ ◀️ 🔼 🔽 ➡️ ⬅️ ⬆️ ⬇️ ↗️ ↘️ ↙️ ↖️ ↕️ ↔️ ↪️ ↩️ ⤴️ ⤵️ 🔀 🔁 🔂 🔄 🔃 🎵 🎶 ➕ ➖ ➗ ✖️ 💲 💱 ™️ ©️ ®️ ✔️ ☑️ 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪ 🔺 🔻 🔸 🔹 🔶 🔷 🔳 🔲 ▪️ ▫️ ◾ ◽ ◼️ ◻️ 🔈 🔇 🔉 🔊 🔔 🔕 📣 📢 💬 💭 ♠️ ♣️ ♥️ ♦️ 🃏 🎴 🀄')
        },
        {
            id: 'flags',
            label: 'Flags',
            icon: '🏁',
            items: list('🏁 🚩 🏳️ 🏴 🇺🇸 🇬🇧 🇨🇦 🇦🇺 🇳🇿 🇮🇳 🇵🇰 🇧🇩 🇱🇰 🇳🇵 🇦🇪 🇸🇦 🇶🇦 🇰🇼 🇧🇭 🇴🇲 🇮🇷 🇮🇶 🇯🇴 🇱🇧 🇹🇷 🇪🇬 🇿🇦 🇳🇬 🇰🇪 🇲🇦 🇩🇪 🇫🇷 🇮🇹 🇪🇸 🇵🇹 🇳🇱 🇧🇪 🇨🇭 🇸🇪 🇳🇴 🇩🇰 🇫🇮 🇵🇱 🇺🇦 🇷🇺 🇬🇷 🇮🇪 🇦🇹 🇧🇷 🇲🇽 🇦🇷 🇨🇱 🇨🇴 🇯🇵 🇰🇷 🇨🇳 🇭🇰 🇸🇬 🇲🇾 🇮🇩 🇵🇭 🇹🇭 🇻🇳')
        }
    ];

    var lookup = {};
    groups.forEach(function (group) {
        group.items.forEach(function (emoji) {
            lookup[emoji] = true;
        });
    });

    function buttons(items, className) {
        return items.map(function (emoji) {
            return '<button type="button" class="' + className + '" data-emoji="' + emoji + '">' + emoji + '</button>';
        }).join('');
    }

    function shell(className) {
        var tabs = groups.map(function (group, index) {
            return '<button type="button" class="lc-emoji-tab' + (index === 0 ? ' is-on' : '') + '" data-emoji-tab="' + group.id + '" title="' + group.label + '" aria-label="' + group.label + '">' + group.icon + '</button>';
        }).join('');
        return '<div class="lc-emoji-grid" data-emoji-grid>' + buttons(groups[0].items, className) + '</div>' +
            '<div class="lc-emoji-tabs" data-emoji-tabs>' + tabs + '</div>';
    }

    function bind(picker, className) {
        if (!picker || picker._lcEmojiBound) return;
        picker._lcEmojiBound = true;
        var grid = picker.querySelector('[data-emoji-grid]');
        var tabs = picker.querySelector('[data-emoji-tabs]');
        if (!grid || !tabs) return;
        tabs.addEventListener('click', function (ev) {
            var tab = ev.target.closest('[data-emoji-tab]');
            if (!tab) return;
            ev.preventDefault();
            ev.stopPropagation();
            tabs.querySelectorAll('.lc-emoji-tab').forEach(function (btn) {
                btn.classList.toggle('is-on', btn === tab);
            });
            var id = tab.getAttribute('data-emoji-tab');
            var group = null;
            for (var i = 0; i < groups.length; i++) {
                if (groups[i].id === id) group = groups[i];
            }
            grid.innerHTML = buttons(group ? group.items : [], className);
            grid.scrollTop = 0;
        });
    }

    w.LCEmoji = {
        groups: groups,
        has: function (emoji) {
            return !!lookup[emoji];
        },
        shell: shell,
        bind: bind
    };
})(window);
