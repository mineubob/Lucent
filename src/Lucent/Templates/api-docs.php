<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation</title>
    <style>
        :root {
            --primary-color: #4a90e2;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --text-color: #333;
            --endpoint-path-text-color: #666;
            --border-color: #e1e4e8;
            --bg-color: #f8f9fa;
            --endpoint-bg-color: #ffffff;
            --code-bg: #f6f8fa;
        }

        :root[dark-theme] {
            --primary-color: #9d7fe2;
            --success-color: #34c759;
            --warning-color: #ff9900;
            --danger-color: #e74c3c;
            --text-color: #fff;
            --endpoint-path-text-color: #fff;
            --border-color: #333;
            --bg-color: #2b2b2b;
            --endpoint-bg-color: #333;
            --code-bg: #1f1f1f;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background: var(--bg-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            width: 100%;
        }

        /* Header styles */
        .header {
            background: var(--primary-color);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        /* Navigation */
        .nav {
            background: var(--bg-color);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-content {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem 0;
        }

        /* Search bar */
        .search-bar {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            width: 100%;
            max-width: 600px;
            font-size: 1rem;
        }

        :root[dark-theme] .search-bar {
            background: #333;
            color: white;
        }

        /* Endpoint styles */
        .endpoint {
            background: var(--endpoint-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .endpoint-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.5rem;
        }

        .endpoint-path {
            font-family: 'SFMono-Regular', Consolas, monospace;
            color: var(--endpoint-path-text-color);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .endpoint-path .parameter {
            color: var(--primary-color);
        }

        .endpoint-content {
            padding: 1.5rem;
        }

        /* HTTP Method badges */
        .method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.875rem;
            min-width: 80px;
            text-align: center;
        }

        .method.get {
            background: var(--primary-color);
            color: white;
        }

        .method.post {
            background: var(--success-color);
            color: white;
        }

        .method.put {
            background: var(--warning-color);
            color: white;
        }

        .method.delete {
            background: var(--danger-color);
            color: white;
        }

        /* Parameters section */
        .parameters {
            background: var(--bg-color);
            border-radius: 6px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .parameter {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .parameter:last-child {
            margin-bottom: 0;
        }

        .parameter-name {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-weight: 600;
            min-width: 120px;
            color: var(--primary-color);
        }

        /* Validation rules section */
        .validation-rules {
            background: var(--bg-color);
            border-radius: 6px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .rules-list {
            list-style: none;
            margin-top: 1rem;
        }

        .rules-list li {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: flex-start;
        }

        .rule-name {
            font-weight: 600;
            min-width: 150px;
            margin-right: 1rem;
        }

        /* Response section */
        .response-section {
            margin-top: 1.5rem;
        }

        .response {
            background: var(--code-bg);
            border-radius: 6px;
            margin: 1rem 0;
        }

        .response-header {
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.05);
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
            font-weight: 600;
        }

        .response-body {
            padding: 1rem;
        }

        pre {
            margin: 0;
            padding: 1rem;
            overflow-x: auto;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        /* Footer */
        .footer {
            margin-top: auto;
            background: var(--bg-color);
            border-top: 1px solid var(--border-color);
            padding: 1.5rem 0;
            text-align: center;
            color: #666;
        }

        .dark-mode-toggle {
            background-color: var(--endpoint-bg-color);
            color: var(--text-color);
            padding: 15px 32px;
            text-align: center;
            display: inline-block;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            max-height: min-content;
        }
    </style>
</head>

<body>
    <header class="header">
        <div class="container" style="display: flex; flex-direction: row; justify-content: space-between;">
            <div>
                <h1>API Documentation</h1>
                <p>Last updated: {{date}}</p>
            </div>
            <div>
                <button id="theme-toggle" class="dark-mode-toggle">Toggle Dark Mode</button>
            </div>
        </div>
    </header>

    <nav class="nav">
        <div class="container nav-content">
            <input type="text" class="search-bar" placeholder="Search endpoints...">
        </div>
    </nav>

    <script>
        const themeToggle = document.getElementById('theme-toggle');

        themeToggle.addEventListener('click', () => {
            const root = document.documentElement;

            if (root.hasAttribute('dark-theme')) {
                root.removeAttribute('dark-theme');
            } else {
                root.setAttribute('dark-theme', 'true');
            }
        });
    </script>

    <div class="container">
        {{endpoints}}
    </div>

    <footer class="footer">
        <div class="container">
            Generated by Lucent {{version}}
            <br>
            <small>Documentation built on {{date}}</small>
        </div>
    </footer>

    <script>
        const searchBar = document.querySelector('.search-bar');
        const endpoints = document.querySelectorAll('.endpoint');

        searchBar.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();

            endpoints.forEach(endpoint => {
                const text = endpoint.textContent.toLowerCase();
                endpoint.style.display = text.includes(searchTerm) ? 'block' : 'none';
            });
        });
    </script>
</body>

</html>