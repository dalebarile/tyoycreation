class SimpleChatBot {
    constructor() {
        this.isOpen = false;
        this.init();
    }

    init() {
        this.createChatbotHTML();
        this.bindEvents();
    }

    createChatbotHTML() {
        const html = `
            <div class="simple-chatbot">
                <div class="chat-window" id="chatWindow">
                    <div class="chat-header">
                        <span>🤖 Event Assistant (AI)</span>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button class="close-btn" title="Clear chat" onclick="chatbot.clearHistory()" style="font-size:14px;">🗑</button>
                            <button class="close-btn" onclick="chatbot.close()">×</button>
                        </div>
                    </div>
                    <div class="chat-messages" id="chatMessages">
                        <div class="welcome-msg">
                            <p><strong>Hello! I'm your AI event assistant.</strong></p>
                            <p>I can help you check availability, list facilities, create events, and more. Just ask me anything!</p>
                        </div>
                    </div>
                    <div class="chat-input">
                        <input type="text" id="chatInput" placeholder="Ask me anything...">
                        <button onclick="chatbot.sendMessage()">Send</button>
                    </div>
                </div>
                <button class="chat-toggle" id="chatToggle" onclick="chatbot.toggle()">💬</button>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', html);
    }

    bindEvents() {
        document.getElementById('chatInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.sendMessage();
        });
    }

    toggle() {
        const win = document.getElementById('chatWindow');
        this.isOpen = !this.isOpen;
        win.style.display = this.isOpen ? 'flex' : 'none';
        if (this.isOpen) {
            document.getElementById('chatInput').focus();
            this.scrollToBottom();
        }
    }

    close() {
        document.getElementById('chatWindow').style.display = 'none';
        this.isOpen = false;
    }

    async clearHistory() {
        if (!confirm('Clear chat history?')) return;
        try {
            const fd = new FormData();
            fd.append('action', 'clear_history');
            await fetch('chatbot.php', { method: 'POST', body: fd });
        } catch (e) {}
        const msgs = document.getElementById('chatMessages');
        msgs.innerHTML = `
            <div class="welcome-msg">
                <p><strong>Chat cleared.</strong></p>
                <p>How can I help you?</p>
            </div>`;
    }

    async sendMessage() {
        const input = document.getElementById('chatInput');
        const message = input.value.trim();
        if (!message) return;

        input.value = '';
        input.disabled = true;
        this.addMessage(message, true);
        this.showTyping();

        try {
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('message', message);

            const res  = await fetch('chatbot.php', { method: 'POST', body: fd });
            const data = await res.json();

            this.hideTyping();

            if (data.response) {
                this.addMessage(data.response, false);

                // If an action was performed (event created/deleted/etc.)
                // show a subtle refresh hint
                if (data.action_done) {
                    this.addSystemNote('✨ Page data has changed — refresh to see updates.');
                }
            } else if (data.error) {
                this.addMessage('⚠️ ' + data.error, false);
            }
        } catch (err) {
            this.hideTyping();
            this.addMessage('❌ Connection error. Please try again.', false);
        } finally {
            input.disabled = false;
            input.focus();
        }
    }

    addMessage(text, isUser) {
        const container = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.className = `message ${isUser ? 'user' : 'bot'}`;
        div.innerHTML = isUser ? this.escapeHtml(text) : this.formatMarkdown(text);
        container.appendChild(div);
        this.scrollToBottom();
    }

    addSystemNote(text) {
        const container = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.style.cssText = 'text-align:center;font-size:11px;color:#888;padding:4px 0;';
        div.textContent = text;
        container.appendChild(div);
        this.scrollToBottom();
    }

    // Convert basic markdown to HTML for bot messages
    formatMarkdown(text) {
        // Escape HTML first for safety
        text = this.escapeHtml(text);

        // **bold**
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

        // *italic*
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');

        // Line breaks
        text = text.replace(/\n/g, '<br>');

        // Bullet lists: lines starting with - or •
        text = text.replace(/((?:^|<br>)[•\-] .+)+/g, (match) => {
            const items = match.split(/<br>/).filter(s => s.match(/^[•\-] /));
            return '<ul style="margin:6px 0 6px 16px;padding:0">'
                + items.map(i => `<li>${i.replace(/^[•\-] /, '')}</li>`).join('')
                + '</ul>';
        });

        return text;
    }

    escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    showTyping() {
        const container = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.className = 'message bot typing';
        div.id = 'typingIndicator';
        div.innerHTML = '<span style="letter-spacing:2px">•••</span>';
        container.appendChild(div);
        this.scrollToBottom();
    }

    hideTyping() {
        const el = document.getElementById('typingIndicator');
        if (el) el.remove();
    }

    scrollToBottom() {
        const c = document.getElementById('chatMessages');
        c.scrollTop = c.scrollHeight;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.chatbot = new SimpleChatBot();
});