# antd v6 — Tooling: MCP Server & CLI

> The official Ant Design MCP server and `@ant-design/cli` command-line tool. Both ship in
> the **same package** (`@ant-design/cli`); the MCP server is the `antd mcp` subcommand.
> Source: https://ant.design/docs/react/mcp + https://ant.design/docs/react/cli

## Table of Contents
- [Why this matters](#why-this-matters)
- [`@ant-design/cli` — installation](#ant-designcli--installation)
- [CLI commands](#cli-commands)
- [CLI global flags](#cli-global-flags)
- [CLI usage examples](#cli-usage-examples)
- [MCP server (`antd mcp`)](#mcp-server-antd-mcp)
- [MCP tools (7)](#mcp-tools-7)
- [MCP prompts (2)](#mcp-prompts-2)
- [MCP client configuration](#mcp-client-configuration)
- [Community alternative MCP server](#community-alternative-mcp-server)
- [Fallback when MCP is unavailable](#fallback-when-mcp-is-unavailable)

---

## Why this matters

`@ant-design/cli` brings Ant Design knowledge (component props, docs, demos, tokens,
semantic structure, changelogs) to the terminal and to AI agents — **fully offline**, since
all metadata ships inside the package. It covers antd **v4, v5 and v6** (55+ per-minor
snapshots), so it can answer version-specific questions and drive v5→v6 migration. The same
package exposes an MCP server (`antd mcp`) so IDEs / AI tools can query that knowledge
programmatically instead of guessing or hitting the web.

When this skill's static reference is not enough (e.g. a rare prop, an exact demo, a token
value for a specific minor), the CLI / MCP server is the authoritative live source.

## `@ant-design/cli` — installation

```bash
npm install -g @ant-design/cli
# command name after install: antd
```

- Package: **`@ant-design/cli`**
- The MCP server requires **v6.3.5+** of this package.
- Works offline — metadata for v4/v5/v6 is bundled.

## CLI commands

### Knowledge query commands

| Command | Purpose |
|---------|---------|
| `antd list` | List all components with bilingual names, categories, and "since" versions |
| `antd info <Component>` | Props table — types, defaults, deprecation status |
| `antd doc <Component>` | Full markdown documentation for a component |
| `antd demo <Component> [name]` | Runnable demo source (TSX); `[name]` selects a specific demo |
| `antd token [Component]` | Global design tokens, or component-level tokens when `Component` given |
| `antd semantic <Component>` | `classNames` / `styles` semantic structure + usage examples |
| `antd changelog [v1] [v2] [component]` | Changelog entries / API diffs between two versions |

### Project analysis commands

| Command | Purpose |
|---------|---------|
| `antd doctor` | 10 diagnostic checks: React compatibility, duplicate antd installs, peer deps, SSR, babel plugins |
| `antd usage [dir]` | Analyze antd import statistics and per-component usage breakdown |
| `antd lint [target]` | Flag deprecated APIs, accessibility gaps, performance issues, best-practice violations |
| `antd migrate <from> <to>` | Generate a migration checklist with auto-fixable items marked (e.g. `antd migrate 5 6`) |
| `antd env [dir]` | Collect environment info for bug reports |
| `antd bug` | Open a bug report against the antd repository |

### MCP server command

| Command | Purpose |
|---------|---------|
| `antd mcp` | Start the MCP server (7 tools + 2 prompts) for IDE / AI-agent integration |

## CLI global flags

| Flag | Values | Default | Notes |
|------|--------|---------|-------|
| `--format` | `json` / `text` / `markdown` | `text` | use `json` when piping into tooling |
| `--version` | a specific antd version, e.g. `6.4.3`, `5.20.0` | auto-detect | which antd version's metadata to query |
| `--lang` | `en` / `zh` | `en` | output language |
| `--detail` | boolean flag | `false` | verbose output |

The CLI uses "smart matching" — typo correction via Levenshtein distance — so
`antd info Buton` still resolves to `Button`.

## CLI usage examples

```bash
# Inspect Button props for v6
antd info Button --version 6.4.3

# Get the Table docs as markdown
antd doc Table --format markdown

# Pull a runnable demo
antd demo Form basic

# Component-level design tokens
antd token Button

# Diff the Select API between v5 and v6
antd changelog 5 6 Select

# Plan a v5 -> v6 migration of the current project
antd migrate 5 6

# Health-check the current project's antd setup
antd doctor

# See which antd components the project imports
antd usage ./src
```

### Adding the CLI's bundled skill to an agent

`@ant-design/cli` ships a built-in skill file for agent integration:

```bash
npx skills add ant-design/ant-design-cli
```

Compatible with Claude Code, Cursor, Codex, and Gemini CLI via the skills protocol.

## MCP server (`antd mcp`)

The Ant Design MCP server lets AI models query antd documentation and components through
the Model Context Protocol — accurate, version-aware answers instead of web guessing.

- Provided by `@ant-design/cli` **v6.3.5+**.
- Launch: `antd mcp`.
- Pin a version with `--version` (e.g. `antd mcp --version 5.20.0`) to make all tool
  responses target that antd major/minor; otherwise it auto-detects.
- Transport: launched as a local subprocess (stdio-style) by the MCP client config below.
  The official docs do not document an SSE / HTTP transport.

## MCP tools (7)

| Tool | Capability |
|------|-----------|
| `antd_list` | Enumerate available components |
| `antd_info` | Retrieve a component's property specifications |
| `antd_doc` | Fetch complete component documentation |
| `antd_demo` | Access runnable code examples |
| `antd_token` | Query design token values |
| `antd_semantic` | Inspect semantic DOM structure and styling hooks (`classNames`/`styles`) |
| `antd_changelog` | Analyze API changes across versions |

These mirror the CLI knowledge commands one-to-one (`antd info`/`doc`/`demo`/`token`/
`semantic`/`changelog`/`list`).

## MCP prompts (2)

| Prompt | Purpose |
|--------|---------|
| `antd-expert` | Positions the agent as an Ant Design specialist |
| `antd-page-generator` | Assists with component-based page creation |

## MCP client configuration

The MCP server is registered with the same `mcpServers` JSON block across clients. Base
config:

```json
{
  "mcpServers": {
    "antd": {
      "command": "antd",
      "args": ["mcp"]
    }
  }
}
```

Pin a specific antd version for all responses:

```json
{
  "mcpServers": {
    "antd": {
      "command": "antd",
      "args": ["mcp", "--version", "6.4.3"]
    }
  }
}
```

Supported clients and where the config lives:

| Client | Config location |
|--------|-----------------|
| Claude Code | `mcpServers` block in Claude settings |
| Cursor | `.cursor/mcp.json` or Settings → Features → MCP |
| Windsurf | `~/.codeium/windsurf/mcp_config.json` |
| Codex | `.codex/mcp.json` |
| Gemini CLI | MCP configuration |
| Trae / Qoder / Neovate Code | MCP settings / prompt-based configuration |

> `antd` must be on `PATH` (i.e. `@ant-design/cli` installed globally) for `command: "antd"`
> to resolve. Alternatively use `command: "npx"` with `args: ["-y", "@ant-design/cli",
> "mcp"]`.

## Community alternative MCP server

A third-party package offering similar capabilities:

```json
{
  "mcpServers": {
    "antd-components": {
      "command": "npx",
      "args": ["-y", "@jzone-mcp/antd-components-mcp"]
    }
  }
}
```

Tools: `list-components`, `get-component-docs`, `list-component-examples`,
`get-component-changelog`. Prefer the official `@ant-design/cli` server unless there is a
specific reason not to.

## Fallback when MCP is unavailable

If the environment does not support MCP, the LLM-friendly aggregate docs are still
available over plain HTTP:

- `https://ant.design/llms.txt` — navigation index of all docs and components
- `https://ant.design/llms-full.txt` — complete component documentation + examples
- `https://ant.design/llms-semantic.md` — semantic DOM structure for every component
- `https://ant.design/components/<component>.md` — single component (e.g. `button.md`)
