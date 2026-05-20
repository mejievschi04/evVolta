## graphify

This project has a graphify knowledge graph at graphify-out/.

Use the project-local Graphify command:
- Windows PowerShell: `.\.graphify-venv\Scripts\python -m graphify`
- Example: `.\.graphify-venv\Scripts\python -m graphify explain HistoryScreen --graph graphify-out\graph.json`

Rules:
- Before answering architecture or codebase questions, read graphify-out/GRAPH_REPORT.md for god nodes and community structure when it exists.
- If graphify-out/wiki/index.md exists, navigate it before reading raw files.
- For cross-module "how does X relate to Y" questions, prefer Graphify `query`, `path`, or `explain` over broad text search.
- After modifying code files in this session, run `.\.graphify-venv\Scripts\python -m graphify update .` to keep the graph current (AST-only, no API cost).
