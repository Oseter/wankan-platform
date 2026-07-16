/**
 * 万刊网 — 轻量 Markdown 渲染器
 * 支持：标题、粗体、斜体、代码块、行内代码、引用、列表、链接、图片、表格、分隔线、删除线
 */
const Markdown = (function() {

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function render(md) {
    if (!md) return '';
    let lines = md.split('\n');
    let html = [];
    let i = 0;
    let inCodeBlock = false;
    let codeLang = '';
    let codeContent = [];

    while (i < lines.length) {
      let line = lines[i];

      // 代码块
      const fenceMatch = line.match(/^```(\w*)/);
      if (fenceMatch) {
        if (!inCodeBlock) {
          inCodeBlock = true;
          codeLang = fenceMatch[1] || '';
          codeContent = [];
          i++;
          continue;
        } else {
          inCodeBlock = false;
          html.push('<pre><code class="lang-' + escapeHtml(codeLang) + '">' + escapeHtml(codeContent.join('\n')) + '</code></pre>');
          i++;
          continue;
        }
      }
      if (inCodeBlock) {
        codeContent.push(line);
        i++;
        continue;
      }

      // 标题
      const hMatch = line.match(/^(#{1,6})\s+(.+)/);
      if (hMatch) {
        const level = hMatch[1].length;
        html.push('<h' + level + '>' + inline(hMatch[2]) + '</h' + level + '>');
        i++;
        continue;
      }

      // 分隔线
      if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) {
        html.push('<hr>');
        i++;
        continue;
      }

      // 引用块
      if (line.startsWith('> ')) {
        let quote = [line.slice(2)];
        while (i + 1 < lines.length && lines[i + 1].startsWith('> ')) {
          i++;
          quote.push(lines[i].slice(2));
        }
        html.push('<blockquote>' + inline(quote.join('\n')) + '</blockquote>');
        i++;
        continue;
      }

      // 无序列表
      if (/^[-*+]\s+/.test(line)) {
        let items = [];
        while (i < lines.length && /^[-*+]\s+/.test(lines[i])) {
          items.push('<li>' + inline(lines[i].replace(/^[-*+]\s+/, '')) + '</li>');
          i++;
        }
        html.push('<ul>' + items.join('') + '</ul>');
        continue;
      }

      // 有序列表
      if (/^\d+\.\s+/.test(line)) {
        let items = [];
        while (i < lines.length && /^\d+\.\s+/.test(lines[i])) {
          items.push('<li>' + inline(lines[i].replace(/^\d+\.\s+/, '')) + '</li>');
          i++;
        }
        html.push('<ol>' + items.join('') + '</ol>');
        continue;
      }

      // 表格
      if (line.includes('|') && i + 1 < lines.length && /^\|?[\s-:|]+\|/.test(lines[i + 1])) {
        let headers = line.split('|').map(s => s.trim()).filter(s => s !== '');
        i += 2; // skip header and separator
        let rows = [];
        while (i < lines.length && lines[i].includes('|') && lines[i].trim() !== '') {
          let cells = lines[i].split('|').map(s => s.trim());
          // Remove empty first/last from leading/trailing pipes
          if (cells[0] === '') cells.shift();
          if (cells[cells.length - 1] === '') cells.pop();
          rows.push(cells);
          i++;
        }
        let tableHtml = '<table><thead><tr>';
        headers.forEach(h => tableHtml += '<th>' + inline(h) + '</th>');
        tableHtml += '</tr></thead><tbody>';
        rows.forEach(row => {
          tableHtml += '<tr>';
          headers.forEach((_, idx) => tableHtml += '<td>' + inline(row[idx] || '') + '</td>');
          tableHtml += '</tr>';
        });
        tableHtml += '</tbody></table>';
        html.push(tableHtml);
        continue;
      }

      // 空行
      if (line.trim() === '') {
        i++;
        continue;
      }

      // 普通段落（连续非空行合并）
      let para = [line];
      while (i + 1 < lines.length && lines[i + 1].trim() !== '' &&
        !/^#{1,6}\s/.test(lines[i + 1]) && !/^```/.test(lines[i + 1]) &&
        !/^[-*+]\s/.test(lines[i + 1]) && !/^\d+\.\s/.test(lines[i + 1]) &&
        !lines[i + 1].startsWith('> ') && !/^(-{3,}|\*{3,})$/.test(lines[i + 1].trim()) &&
        !(lines[i + 1].includes('|') && i + 2 < lines.length && /^\|?[\s-:|]+\|/.test(lines[i + 2]))) {
        i++;
        para.push(lines[i]);
      }
      html.push('<p>' + inline(para.join('\n')) + '</p>');
      i++;
    }

    return html.join('\n');
  }

  function safeUrl(u) {
    u = (u || '').trim();
    return /^(https?:|mailto:|#|\/)/i.test(u) ? u : '#';
  }
  function inline(text) {
    // 先整体转义 HTML，杜绝 <script> / <img onerror> 等存储型 XSS
    text = escapeHtml(text);
    // 图片（URL 走协议白名单，拦 javascript: 等）
    text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (m, alt, url) { return '<img src="' + safeUrl(url) + '" alt="' + alt + '">'; });
    // 链接
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (m, txt, url) { return '<a href="' + safeUrl(url) + '" target="_blank" rel="noopener">' + txt + '</a>'; });
    // 粗体+斜体
    text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    // 粗体
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');
    // 斜体
    text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
    text = text.replace(/_(.+?)_/g, '<em>$1</em>');
    // 删除线
    text = text.replace(/~~(.+?)~~/g, '<del>$1</del>');
    // 行内代码
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
    // 换行
    text = text.replace(/\n/g, '<br>');
    return text;
  }

  return { render, escapeHtml };
})();
