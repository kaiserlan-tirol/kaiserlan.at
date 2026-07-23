# MCP Local Setup

This folder contains the shared template for local MCP configuration.

## Files

- `mcp.json.example` is the team-shared template
- the real local config should live in `.vscode/mcp.json`

## Local setup

1. Copy `mcp.json.example` to `.vscode/mcp.json`
2. Replace the placeholder values:
   - `YOUR_TIMEZONE`
   - `YOUR_TENANT_UUID`
   - `YOUR_BACKEND_ID`
3. Restart the MCP server in VS Code or reload the window

## Example command

From the repository root:

```bash
cp mcp.json.example mcp.json
```
