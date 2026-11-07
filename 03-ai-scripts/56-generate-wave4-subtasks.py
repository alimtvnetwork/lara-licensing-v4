#!/usr/bin/env python3
"""Generates slots 02 through 20 for Wave 4 100 UI improvements in .ai-memory/plans/subtasks/403-improvements/."""

from __future__ import annotations

import os

SUBTASKS_DIR = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", ".ai-memory", "plans", "subtasks", "403-improvements")
)

SLOTS_DATA = [
    {
        "slot": 2,
        "items": "006–010",
        "domain": "Cluster 01: Heatmap & Complexity Heat Scale Tokens (Part B)",
        "items_data": [
            {
                "id": "006",
                "title": "Tokenize Terminal Tree Nodes via SafeTokyoStyle",
                "file": "cli/formatter/terminaltree.go (`L104–L118`)",
                "func": "renderTermNode",
                "rule": "Cluster 01: Elimination of Raw ANSI Escapes",
                "severity": "Critical",
                "analysis": "Directly injects constants.ColorNeonGreen and constants.ColorNeonGold terminal escapes, bypassing central termout tokens.",
                "action": "Migrate to termout.SafeTokyoStyle(termout.TokyoOK) for repositories and termout.SafeTokyoStyle(termout.TokyoPrimary) for folders."
            },
            {
                "id": "007",
                "title": "Tokenized Tokyo Night Live Progress in Scan Progress Renderer",
                "file": "cli/cmdscan/scanprogress.go (`L75–L84`)",
                "func": "renderFrameLocked",
                "rule": "Cluster 01: Cyan Highlight & Progress Tokens",
                "severity": "Major",
                "analysis": "Progress frames concatenate raw constants.ColorCyan and constants.ColorGreen format strings.",
                "action": "Replace with termout.RenderTokyoProgress and termout.TokyoOKPill with adaptive TTY checks."
            },
            {
                "id": "008",
                "title": "Tokenize Terminal Scan Banner via termout.TokyoBannerStyle",
                "file": "cli/formatter/terminal.go (`L50–L58`)",
                "func": "printBanner",
                "rule": "Cluster 01: Tokenized Gradient & Banner Architecture",
                "severity": "Major",
                "analysis": "Uses raw ANSI strings constants.ColorCyan and constants.ColorGreen with ASCII box characters.",
                "action": "Wrap title in termout.TokyoBoxStyle(termout.TokyoBannerStyle(title)) and format repo counts via termout.TokyoOKPill."
            },
            {
                "id": "009",
                "title": "Semantic Tag Tokens in Repository List Rendering",
                "file": "cli/formatter/terminal.go (`L72–L82`)",
                "func": "printOneRepo",
                "rule": "Cluster 01: Semantic Token Decoupling",
                "severity": "Minor",
                "analysis": "Raw ANSI string concatenation causes ANSI reset bleed across terminal emulators.",
                "action": "Use termout.TokyoTag and termout.SafeTokyoStyle to cleanly separate semantic tokens from layout."
            },
            {
                "id": "010",
                "title": "Semantic Status Badges for Scan Diff Delta Reporting",
                "file": "cli/cmdscan/scan_diff.go (`L229–L246`)",
                "func": "printScanDiffReport",
                "rule": "Cluster 01: Semantic Token Decoupling",
                "severity": "Major",
                "analysis": "Scan diff outputs a single unstyled plain text line without color-coded semantic pills.",
                "action": "Encapsulate added, removed, and moved repository counts in termout.TokyoOKPill, TokyoErrorPill, and TokyoWarnPill."
            }
        ]
    },
    {
        "slot": 3,
        "items": "011–015",
        "domain": "Cluster 02: WCAG 2.2 AA/AAA Contrast in Heatmaps & Fleet Monitors (Part A)",
        "items_data": [
            {
                "id": "011",
                "title": "WCAG 2.2 AA Relative Luminance Verification in Heatmap Table",
                "file": "cli/cmdheat/complexity_map.go (`L196–L199`)",
                "func": "printHeatmapRow",
                "rule": "Cluster 02: WCAG 2.2 AA/AAA Contrast Pass",
                "severity": "Major",
                "analysis": "Prints file paths directly with default terminal foreground, lacking required 4.5:1 contrast against surface on dark profiles.",
                "action": "Apply termout.SafeAdaptiveStyle with guaranteed 7.0:1 AAA contrast for scores and file paths."
            },
            {
                "id": "012",
                "title": "Fix Inverted Low-Contrast Neon Gold in Terminal Tree",
                "file": "cli/formatter/terminaltree.go (`L114–L117`)",
                "func": "renderTermNode",
                "rule": "Cluster 02: Body Text Contrast AA/AAA Pass",
                "severity": "Critical",
                "analysis": "ColorNeonGold gives an abysmal contrast ratio of 1.6:1 on light terminal backgrounds, failing WCAG AA.",
                "action": "Replace with SafeAdaptiveStyle('#2e3c64', '#7aa2f7') providing >8.0:1 AAA contrast on both light and dark themes."
            },
            {
                "id": "013",
                "title": "Colorblind-Safe Icon Redundancy in Terminal Progress Summary",
                "file": "cli/cmdscan/scanprogress.go (`L87–L97`)",
                "func": "renderFinalLocked",
                "rule": "Cluster 02: Non-Color Visual Redundancy",
                "severity": "Major",
                "analysis": "Relies entirely on ColorGreen to signal success without glyphs or textual pill badges.",
                "action": "Pair color with explicit checkmark glyphs (✔), TokyoOKPill('COMPLETE'), and distinct metric highlights."
            },
            {
                "id": "014",
                "title": "Dual-Mode Glyphs with High-Contrast Fallbacks in Folder Tree",
                "file": "cli/cmdfoldertree/render_icon.go (`L18–L32`)",
                "func": "resolveFileIcon",
                "rule": "Cluster 02: Non-Color Visual Redundancy",
                "severity": "Minor",
                "analysis": "Monolithic emojis render with unpredictable 1-cell or 2-cell column widths in PowerShell, breaking tree indentation.",
                "action": "Implement dual-mode glyph resolver providing standardized 2-char ASCII/NerdFont tokens."
            },
            {
                "id": "015",
                "title": "High-Contrast Pill Encapsulation for Reasoning States",
                "file": "cli/heatmap/heatmap_cmd_render.go (`L48–L60`)",
                "func": "formatReasoningState",
                "rule": "Cluster 02: Contrast Ratios & WCAG 2.2 AA Compliance",
                "severity": "Major",
                "analysis": "Emits raw foreground color codes without background tiles, allowing WARN/FAIL to blend into paths.",
                "action": "Encapsulate states in Tokyo Night pills guaranteeing 7.0:1 AAA contrast against the surface well."
            }
        ]
    },
    {
        "slot": 4,
        "items": "016–020",
        "domain": "Cluster 02: WCAG 2.2 AA/AAA Contrast in Heatmaps & Fleet Monitors (Part B)",
        "items_data": [
            {
                "id": "016",
                "title": "High-Contrast Directory & File Labels in Tree Search Results",
                "file": "cli/cmdfoldertree/treesearch_match.go (`L240–L259`)",
                "func": "FormatTreeItemsText",
                "rule": "Cluster 02: Body Text Contrast AA/AAA Pass",
                "severity": "Minor",
                "analysis": "[DIR] and [FILE] labels are printed as plain brackets with zero contrast distinction.",
                "action": "Wrap in TokyoTag('DIR') and TokyoTag('FILE') with bold primary styling on directory paths."
            },
            {
                "id": "017",
                "title": "Contrast Prioritization in Scan Verification Summary",
                "file": "cli/cmdscan/scan_verify.go (`L194–L213`)",
                "func": "printScanVerifyReport",
                "rule": "Cluster 02: Contrast Ratios & Focal Differentiation",
                "severity": "Major",
                "analysis": "Stale and unindexed repository counts are emitted in a single unstyled line, obscuring corruption alerts.",
                "action": "Isolate stale counts in termout.TokyoErrorPill and unindexed repositories in termout.TokyoWarnPill."
            },
            {
                "id": "018",
                "title": "Structured Tokyo Warn Pills in Error Report Finalization",
                "file": "cli/cmdscan/scanerrorreport.go (`L67–L87`)",
                "func": "finalizeErrorReport",
                "rule": "Cluster 02: Focus Ring & Alert Contrast",
                "severity": "Minor",
                "analysis": "Raw stderr strings with warning symbols lack background pill styling and visual materiality.",
                "action": "Migrate failure alerts to termout.TokyoWarnPill and diagnostic report paths to TokyoPrimary styling."
            },
            {
                "id": "019",
                "title": "Fleet Version Skew Warning Contrast Enhancement",
                "file": "cli/cmdfleet/fleet_version.go (`L85–L95`)",
                "func": "RunNodesVersion",
                "rule": "Cluster 02: High-Contrast Status Pill Badges",
                "severity": "Major",
                "analysis": "Unreachable and behind status values blend into surrounding host metadata on high-density terminals.",
                "action": "Format BEHIND nodes with termout.TokyoWarnPill and UNREACHABLE nodes with termout.TokyoErrorPill."
            },
            {
                "id": "020",
                "title": "Adaptive Contrast Border Tokens in Fleet Pre-Flight Banners",
                "file": "cli/cmdnodes/nodes_clone_table.go (`L180–L200`)",
                "func": "renderBannerHeaderBox",
                "rule": "Cluster 02: Border Contrast 3:1 Pass",
                "severity": "Minor",
                "analysis": "Banner boxes use hardcoded cyan ANSI codes that wash out on light-mode terminal profiles.",
                "action": "Integrate termout.RoleSGR(termout.RoleBorder) with guaranteed >=3:1 contrast against light and dark canvases."
            }
        ]
    },
    {
        "slot": 5,
        "items": "021–025",
        "domain": "Cluster 03: 4-Plane Depth Hierarchy in Cluster Overviews & MCP Dashboard (Part A)",
        "items_data": [
            {
                "id": "021",
                "title": "Plane 2 Surface Card Container for Complexity Heatmap",
                "file": "cli/cmdheat/complexity_map.go (`L173–L186`)",
                "func": "renderHeatmapTable",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Major",
                "analysis": "Prints directly onto base terminal canvas (Plane 0) using ASCII dashes, blending with subsequent commands.",
                "action": "Elevate tabular dataset onto Plane 2 surface card using termout.TokyoBoxStyle and rounded box borders."
            },
            {
                "id": "022",
                "title": "Plane 1 Raised Well Container for Folder Tree Root Header",
                "file": "cli/cmdfoldertree/render_tree.go (`L17–L28`)",
                "func": "formatRootHeader",
                "rule": "Cluster 03: Plane 1 Raised Wells",
                "severity": "Minor",
                "analysis": "Root directory header is rendered identically to branch lines, lacking visual elevation.",
                "action": "Frame root directory as Plane 1 raised container with subtle padding and bold primary styling."
            },
            {
                "id": "023",
                "title": "Plane 2 Surface Framing for One-Line Gap Previews",
                "file": "cli/cmdfoldertree/render_preview.go (`L9–L20`)",
                "func": "RenderPreview",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Minor",
                "analysis": "Preview entries are dumped as raw text with trailing newlines without structural demarcation.",
                "action": "Encapsulate preview items within subtle tree connector frames (├─, │) and muted path labels."
            },
            {
                "id": "024",
                "title": "Plane 2 Card Containers for Per-Repo Summary Blocks",
                "file": "cli/formatter/terminal.go (`L38–L47`)",
                "func": "printRepoSummaryBlocks",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Major",
                "analysis": "Summary blocks are separated by flat dashes, blending into surrounding scan output.",
                "action": "Wrap summary metrics into Plane 2 surface card with hairline borders to isolate repo telemetry."
            },
            {
                "id": "025",
                "title": "Plane 1 Inset Well for Output Files Manifest",
                "file": "cli/formatter/terminal.go (`L138–L150`)",
                "func": "printOutputFiles",
                "rule": "Cluster 03: Plane 1 Inset Wells",
                "severity": "Minor",
                "analysis": "Lists generated artifacts directly on terminal canvas without depth separation.",
                "action": "Indent and frame artifact list within a raised well using subtle border guides."
            }
        ]
    },
    {
        "slot": 6,
        "items": "026–030",
        "domain": "Cluster 03: 4-Plane Depth Hierarchy in Cluster Overviews & MCP Dashboard (Part B)",
        "items_data": [
            {
                "id": "026",
                "title": "Plane 2 Card Framing for Scan Benchmark Phase Table",
                "file": "cli/cmdscan/scanbenchmark.go (`L90–L110`)",
                "func": "renderBenchmarkTable",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Major",
                "analysis": "Timing benchmarks dump plain ASCII lines without depth layering or card boundaries.",
                "action": "Format phase durations into rounded card table with Tokyo Night header styling."
            },
            {
                "id": "027",
                "title": "Plane 3 Modal Card Elevation in Tree Search Help Menu",
                "file": "cli/cmdfoldertree/help.go (`L25–L45`)",
                "func": "printTreeSearchHelp",
                "rule": "Cluster 03: Plane 3 Floating Popovers & Modals",
                "severity": "Minor",
                "analysis": "Bypasses standard help menu styling by printing raw unicode double box characters.",
                "action": "Migrate to termout.HelpMenu structure with Plane 3 floating dialog visual hierarchy."
            },
            {
                "id": "028",
                "title": "Plane 2 Surface Card for Cognitive Heatmap Overview",
                "file": "cli/heatmap/heatmap_overview_render.go (`L70–L95`)",
                "func": "renderOverviewHeader",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Major",
                "analysis": "Multi-database discovery summary prints raw dash lines directly on canvas ground.",
                "action": "Encapsulate overview metrics within termout.TokyoBoxStyle with clear card boundaries."
            },
            {
                "id": "029",
                "title": "Plane 1 Raised Header Wells in Fleet Command Matrix",
                "file": "cli/cmdnodes/nodes_render.go (`L78–L93`)",
                "func": "renderCommandsMatrixHeader",
                "rule": "Cluster 03: Plane 1 Raised Wells",
                "severity": "Major",
                "analysis": "Matrix column headers lack background elevation, causing text to run into row items.",
                "action": "Apply Plane 1 surface styling with subtle background tint and bold primary text."
            },
            {
                "id": "030",
                "title": "Plane 2 Card Enclosure for Fleet Remote Bootstrap Docket",
                "file": "cli/cmdnodes/nodes_bootstrap.go (`L290–L310`)",
                "func": "renderBootstrapDocket",
                "rule": "Cluster 03: Plane 2 Surface Cards",
                "severity": "Major",
                "analysis": "6-stage bootstrap progress uses plain hyphen divider lines without card enclosure.",
                "action": "Wrap bootstrap docket in rounded card container with distinct stage completion pill badges."
            }
        ]
    },
    {
        "slot": 7,
        "items": "031–035",
        "domain": "Cluster 04: 60/30/10 Balance & Single-Accent in Stash/Release Wizards (Part A)",
        "items_data": [
            {
                "id": "031",
                "title": "60/30/10 Ratio Calibration in Complexity Summary Block",
                "file": "cli/cmdheat/complexity_map.go (`L215–L230`)",
                "func": "SummarizeComplexity",
                "rule": "Cluster 04: 60/30/10 Visual Balance",
                "severity": "Major",
                "analysis": "Summary outputs equal-weight bold text across all metrics without hierarchical focal anchor.",
                "action": "Calibrate to 60% neutral text, 30% structural muted guides, and 10% Tokyo Cyan accent on high-risk bottlenecks."
            },
            {
                "id": "032",
                "title": "Eliminate Competing Neon Accents in Terminal Scan Output",
                "file": "cli/formatter/terminal.go (`L60–L75`)",
                "func": "printRepoList",
                "rule": "Cluster 04: Single Primary Brand Accent Rule",
                "severity": "Critical",
                "analysis": "Combines green, gold, cyan, and white in equal saturation, causing visual fatigue.",
                "action": "Enforce Tokyo Primary as single accent (10%), resting against Tokyo Slate surface (30%) and dark canvas (60%)."
            },
            {
                "id": "033",
                "title": "Harmonize Pastel Color Cycling in Clone Pretty Header",
                "file": "cli/cmdclone/clonepretty.go (`L114–L119`)",
                "func": "printClonePrettyHeader",
                "rule": "Cluster 04: Single Primary Brand Accent Rule",
                "severity": "Minor",
                "analysis": "Cycles through arbitrary pastel ANSI codes, creating a rainbow experience violating 60/30/10 balance.",
                "action": "Standardize clone header on Tokyo Primary and Tokyo Cyan with uniform neutral body text."
            },
            {
                "id": "034",
                "title": "Von Restorff Isolation in Safe Auto-Stash Lifecycle",
                "file": "cli/cmdfix/fix_stashing.go (`L90–L110`)",
                "func": "RenderHumanizedStashCard",
                "rule": "Cluster 04: Von Restorff Isolation Principle",
                "severity": "Major",
                "analysis": "Stash card colors all dirty files equally, failing to visually isolate destructive deletions.",
                "action": "Reserve Tokyo Error red exclusively for deleted files while keeping untracked/modified files in muted/neutral tones."
            },
            {
                "id": "035",
                "title": "Single-Accent Focal Anchor in Release Verification Summary",
                "file": "cli/cmdrelease/release_verify.go (`L160–L180`)",
                "func": "reportReleaseVerify",
                "rule": "Cluster 04: Single Primary Brand Accent Rule",
                "severity": "Minor",
                "analysis": "Every table row uses bold green text, diluting user attention from critical tag mismatch warnings.",
                "action": "Use neutral slate for matching attributes and reserve accent color strictly for mismatched tags or draft alerts."
            }
        ]
    },
    {
        "slot": 8,
        "items": "036–040",
        "domain": "Cluster 04: 60/30/10 Balance & Single-Accent in Stash/Release Wizards (Part B)",
        "items_data": [
            {
                "id": "036",
                "title": "Focal Anchor Hierarchy in Tree Search Match Results",
                "file": "cli/cmdfoldertree/treesearch_match.go (`L260–L280`)",
                "func": "FormatTreeItemsText",
                "rule": "Cluster 04: Von Restorff Isolation Principle",
                "severity": "Minor",
                "analysis": "Matched search terms are rendered identically to surrounding directory paths without visual pop.",
                "action": "Apply Tokyo Amber accent strictly to matching substring tokens, keeping path prefix in 60% muted tone."
            },
            {
                "id": "037",
                "title": "Accent Discipline in Fleet Task Queue Active Indicators",
                "file": "cli/cmdnodes/nodes_tasks_table.go (`L85–L95`)",
                "func": "renderNodeActiveTasks",
                "rule": "Cluster 04: 60/30/10 Visual Balance",
                "severity": "Major",
                "analysis": "Active task badges, task codes, and assigned roles all compete with bright colored text.",
                "action": "Mute assigned roles and timestamps into 60% neutral text, reserving green accent strictly for the RUNNING badge."
            },
            {
                "id": "038",
                "title": "Single-Accent Visual Balance in Clone List Table",
                "file": "cli/cmdclone/clone_list_table.go (`L80–L100`)",
                "func": "printCloneRecordsTable",
                "rule": "Cluster 04: Single Primary Brand Accent Rule",
                "severity": "Major",
                "analysis": "Every cell in clone manifest table uses bright bold cyan, creating unreadable wall of color.",
                "action": "Format table borders and repo paths in muted Tokyo slate, using cyan accent exclusively for active primary aliases."
            },
            {
                "id": "039",
                "title": "Focal Differentiation in Reclone Execution Summary",
                "file": "cli/cmdclone/reclone_summary.go (`L40–L60`)",
                "func": "printRecloneExecuteSummary",
                "rule": "Cluster 04: Von Restorff Isolation Principle",
                "severity": "Minor",
                "analysis": "Target directories and existing directory collision warnings share identical yellow text.",
                "action": "Keep standard target paths in clean white, applying warning amber solely to overwrite risk warnings."
            },
            {
                "id": "040",
                "title": "60/30/10 Surface Calibration in MCP Web Dashboard",
                "file": "cli/cmdmcp/http_server.go (`L106–L123`)",
                "func": "handleHttpDashboard",
                "rule": "Cluster 04: 60/30/10 Visual Balance",
                "severity": "Major",
                "analysis": "Embedded dashboard HTML template lacks cohesive palette balance across tool cards and status bars.",
                "action": "Enforce 60% background canvas (#1a1b26), 30% card surface (#24283b), and 10% Tokyo Cyan (#7aa2f7) interactive controls."
            }
        ]
    },
    {
        "slot": 9,
        "items": "041–045",
        "domain": "Cluster 05: Monospace Typography & Live Text in Trees/Pickers (Part A)",
        "items_data": [
            {
                "id": "041",
                "title": "Fleet Jump Key-Value Pair Monospace Container Refactor",
                "file": "cli/cmdfleet/fleet_jump.go (`L73–L78`)",
                "func": "RunNodesJump",
                "rule": "Cluster 05: Monospace Code Block Containers",
                "severity": "Major",
                "analysis": "Dumps plain lowercase key-value pairs without container styling or distinct command syntax coloring.",
                "action": "Wrap jump command in termout.TokyoBoxStyle container with uppercase tracking (NODE, WORKSPACE, COMMAND)."
            },
            {
                "id": "042",
                "title": "Fleet Nodes Resources Dracula Hardcoded Hex Elimination",
                "file": "cli/cmdnodes/nodes_resources_table.go (`L15–L23`)",
                "func": "resStyleTitle",
                "rule": "Cluster 05: Zero Hardcoded Hex & Centralized Typography Tokens",
                "severity": "Critical",
                "analysis": "Global styles inject foreign Dracula hex values (#bd93f9, #8be9fd), violating Tokyo Night design system.",
                "action": "Migrate to centralized termout.Tokyo* tokens (TokyoPrimary, TokyoCyan, TokyoMuted, TokyoGreen)."
            },
            {
                "id": "043",
                "title": "Multi-Library Rendering Collision Elimination in Nodes Summary",
                "file": "cli/cmdnodes/nodes_summary.go (`L170–L173`)",
                "func": "RunNodesFullSummary",
                "rule": "Cluster 05: Unified Terminal Typography Stack",
                "severity": "Major",
                "analysis": "Imports github.com/pterm/pterm alongside termout and fmt.Printf, causing conflicting font weights and prefixes.",
                "action": "Replace all pterm invocations with standardized termout pill banners (TokyoInfoPill, TokyoOKPill)."
            },
            {
                "id": "044",
                "title": "Parallel Clone Column Clipping & Monospace Measure Calibration",
                "file": "cli/cloner/progress.go (`L70–L80`)",
                "func": "Done",
                "rule": "Cluster 05: Typographic Measure & Text Truncation",
                "severity": "Major",
                "analysis": "Hardcoded %-32s overflows on long repo names, causing visual column wrapping glitches.",
                "action": "Dynamically clamp repository names using termout.TruncateMiddle while maintaining aligned monospaced counters."
            },
            {
                "id": "045",
                "title": "Batch Operation Progress Font & Pterm Dependency Decoupling",
                "file": "cli/cloner/batchprogress.go (`L87–L89`)",
                "func": "Succeed",
                "rule": "Cluster 05: Unified Terminal Typography Stack",
                "severity": "Major",
                "analysis": "Direct calls to pterm.Success/Error introduce uncontrolled colors and arbitrary symbol prefixes.",
                "action": "Standardize batch progress telemetry on termout.TokyoOKPill and termout.SafeTokyoStyle."
            }
        ]
    },
    {
        "slot": 10,
        "items": "046–050",
        "domain": "Cluster 05: Monospace Typography & Live Text in Trees/Pickers (Part B)",
        "items_data": [
            {
                "id": "046",
                "title": "RepoTermBlock Plain Text Hierarchy & Key-Value Typography Upgrade",
                "file": "cli/render/repotermblock.go (`L131–L140`)",
                "func": "buildBlockBody",
                "rule": "Cluster 05: Monospace Typography & Pure DOM Text Hierarchy",
                "severity": "Major",
                "analysis": "All seven metadata keys are emitted with static lowercase labels and fixed 5-space indentation without visual contrast.",
                "action": "Use muted uppercase micro-typography for labels and distinct foreground tokens for URLs and commands."
            },
            {
                "id": "047",
                "title": "ClonePick Dry-Run Command Preview Typography Hierarchy",
                "file": "cli/clonepick/render.go (`L53–L62`)",
                "func": "renderCommands",
                "rule": "Cluster 05: Code Blocks & Shell Invocation Hierarchy",
                "severity": "Minor",
                "analysis": "Command preview prints uncontained text without distinction between prompt symbol $ and command string.",
                "action": "Wrap commands in structured code card with muted prompt symbol and cyan executable string."
            },
            {
                "id": "048",
                "title": "Tree Search Command Output Monospace Alignment",
                "file": "cli/cmdfoldertree/treesearch_match.go (`L150–L170`)",
                "func": "formatSearchSummary",
                "rule": "Cluster 05: Optical Kerning & Monospace Number Alignment",
                "severity": "Minor",
                "analysis": "Search statistics format counts without fixed-width character padding, shifting summary columns.",
                "action": "Use fixed-width digit formatting with %4d and tabular numbers for item counts and byte sizes."
            },
            {
                "id": "049",
                "title": "Batch Failure Report Stderr Monospace Typography Format",
                "file": "cli/cloner/batchreport.go (`L15–L30`)",
                "func": "PrintFailureReport",
                "rule": "Cluster 05: Monospace Code Block Containers",
                "severity": "Major",
                "analysis": "Failure reports on stderr dump raw errors without error code highlights or actionable troubleshooting flags.",
                "action": "Wrap failure summaries in structured error cards with monospaced command retry recommendations."
            },
            {
                "id": "050",
                "title": "Keycap Formatting in Stash Prompt Keyboard Accelerators",
                "file": "cli/cmd/cmd_stash.go (`L44–L54`)",
                "func": "buildStashActionsSection",
                "rule": "Cluster 05: Live Text & Keycap Standards in Interactive Pickers",
                "severity": "Minor",
                "analysis": "Printing raw strings [1], [2], [3] provides no visual distinction between command names and keyboard shortcuts.",
                "action": "Render quick key accelerators using termout.FormatKeyCap reverse-video badge tokens."
            }
        ]
    },
    {
        "slot": 11,
        "items": "051–055",
        "domain": "Cluster 06: Button Variants & Action Grids in MCP/Stash Forms (Part A)",
        "items_data": [
            {
                "id": "051",
                "title": "Fleet Version Status Strings to Semantic Pill Badges",
                "file": "cli/cmdfleet/fleet_version.go (`L73–L88`)",
                "func": "RunNodesVersion",
                "rule": "Cluster 06: High-Contrast Status Capsule Pills",
                "severity": "Major",
                "analysis": "Status values are printed as plain lowercase strings (ok, behind, latest, unreachable), blending into version numbers.",
                "action": "Format status values with standardized capsule pills (TokyoOKPill, TokyoWarnPill, TokyoErrorPill)."
            },
            {
                "id": "052",
                "title": "Ad-Hoc ANSI Escape Status Glyphs in Primary Nodes Matrix",
                "file": "cli/cmdnodes/nodes_render.go (`L175–L189`)",
                "func": "formatCellStatus",
                "rule": "Cluster 06: Standardized Capsule Pill Badges",
                "severity": "Major",
                "analysis": "Concatenates raw ANSI color constants with Unicode circles, causing visual column padding misalignment.",
                "action": "Standardize on termout capsule pills with visual length awareness."
            },
            {
                "id": "053",
                "title": "Nodes Resources Online & Benchmark Marker Normalization",
                "file": "cli/cmdnodes/nodes_resources_table.go (`L78–L86`)",
                "func": "resolveOnlineStatusAndBench",
                "rule": "Cluster 06: Pill Badges & High-Contrast Feedback",
                "severity": "Major",
                "analysis": "Mixes colored symbols with plain text strings, obscuring telemetry metrics from benchmark results.",
                "action": "Standardize on TokyoOKPill('IDLE') and TokyoWarnPill('EXCEEDED') with uniform padding."
            },
            {
                "id": "054",
                "title": "Uncontained Text Tags in Fleet Nodes Task Queue Display",
                "file": "cli/cmdnodes/nodes_tasks_table.go (`L85–L93`)",
                "func": "renderNodeActiveTasks",
                "rule": "Cluster 06: Semantic Selector Variants & Badges",
                "severity": "Major",
                "analysis": "Active and queued subtasks use bracketed strings ▶ [ACTIVE] and • [QUEUED] with manual indentation.",
                "action": "Format tasks using TokyoOKPill('RUNNING') and cyan step badges [X/Y]."
            },
            {
                "id": "055",
                "title": "Pre-Flight Node Readiness Direct ANSI Concatenation Removal",
                "file": "cli/cmdnodes/nodes_clone_table.go (`L109–L124`)",
                "func": "resolvePreFlightBadge",
                "rule": "Cluster 06: Pill Badges & Non-Color Redundancy",
                "severity": "Major",
                "analysis": "Pre-flight badges concatenate ANSI strings with symbols, bypassing pill background rendering.",
                "action": "Migrate to termout.TokyoOKPill('ONLINE'), TokyoWarnPill('AUTH FAIL'), and TokyoErrorPill('UNREACHABLE')."
            }
        ]
    },
    {
        "slot": 12,
        "items": "056–060",
        "domain": "Cluster 06: Button Variants & Action Grids in MCP/Stash Forms (Part B)",
        "items_data": [
            {
                "id": "056",
                "title": "Parallel Cloner In-Flight Progress Live Redraw & State Styling",
                "file": "cli/cloner/progress.go (`L46–L50`)",
                "func": "Begin",
                "rule": "Cluster 06: Tactile Progress Feedback & Hit Geometry",
                "severity": "Major",
                "analysis": "Dumps a new line to stdout on every Begin(), flooding the terminal buffer during parallel clones.",
                "action": "Implement clean worker queue status tracking with in-place row updates and cyan CLONING pills."
            },
            {
                "id": "057",
                "title": "Clonespinner Braille Frame & Terminal Refresh Modernization",
                "file": "cli/cmdclone/clonespinner.go (`L29–L53`)",
                "func": "startCloneSpinner",
                "rule": "Cluster 06: Hardware-Tuned Progress Spinners & Micro-Interactions",
                "severity": "Minor",
                "analysis": "Uses raw escape sequences \\r\\033[K which print as raw artifacts on restricted Windows consoles.",
                "action": "Wrap spinner in termout safe line-clearing and integrate Tokyo Night accent tones."
            },
            {
                "id": "058",
                "title": "Interactive ClonePick Cursor & Checkbox Hit Target Styling",
                "file": "cli/clonepick/picker_view.go (`L77–L102`)",
                "func": "formatRow",
                "rule": "Cluster 06: Minimum Hit Targets & Visual Selection Rings",
                "severity": "Critical",
                "analysis": "Formats active cursor selection with primitive > symbol and monochrome ASCII checkboxes ([x], [ ]).",
                "action": "Upgrade to high-contrast cyan selection indicators and TokyoOKPill('✔') checkmarks."
            },
            {
                "id": "059",
                "title": "CloneNext Batch Progress Colorful Status Glyphs Packaging",
                "file": "cli/cmdclone/clonenextbatchprogress.go (`L59–L70`)",
                "func": "formatColorfulStatus",
                "rule": "Cluster 06: Standardized Capsule Pill Badges",
                "severity": "Major",
                "analysis": "Direct formatting with ColorGreen and inline glyphs fails to provide background contrast.",
                "action": "Package results into termout.TokyoOKPill('SUCCESS') and termout.TokyoErrorPill('FAILED')."
            },
            {
                "id": "060",
                "title": "Action Button Grid in MCP Web Dashboard Tool Cards",
                "file": "cli/cmdmcp/http_server.go (`L147–L151`)",
                "func": "handleHttpTools",
                "rule": "Cluster 06: Touch/Click Hit Target Geometry (>=44x44px)",
                "severity": "Minor",
                "analysis": "Dashboard tool invocation controls are small links with insufficient clickable touch bounds.",
                "action": "Standardize tool execution controls as accessible button pills with >=44px hit bounds."
            }
        ]
    },
    {
        "slot": 13,
        "items": "061–065",
        "domain": "Cluster 07: Sticky Header Persistence & Dynamic Window Resize (Part A)",
        "items_data": [
            {
                "id": "061",
                "title": "Fleet Version Skew Table Header & Divider Modernization",
                "file": "cli/cmdfleet/fleet_version.go (`L91–L99`)",
                "func": "RunNodesVersion",
                "rule": "Cluster 07: Table Header & Sticky Border Materiality",
                "severity": "Major",
                "analysis": "Uses primitive ASCII text hyphens (---- ------- ------) without column alignment safeguards.",
                "action": "Render table using termout.TableConfig with BorderRounded and bold primary headers."
            },
            {
                "id": "062",
                "title": "Fleet Dispatch Help Multi-Block String Concatenation Fix",
                "file": "cli/cmdfleet/fleet_dispatch.go (`L19–L23`)",
                "func": "printFleetDispatchHelp",
                "rule": "Cluster 07: Navigation Physics & Help Menus",
                "severity": "Major",
                "analysis": "Dumps two raw multi-line strings back-to-back, resulting in duplicate usage headers and overlapping sections.",
                "action": "Refactor into unified termout.HelpMenu matching canonical command layout."
            },
            {
                "id": "063",
                "title": "Primary Nodes Table 123-Column Hardcoded Divider Clamping",
                "file": "cli/cmdnodes/nodes_render.go (`L78–L93`)",
                "func": "renderHostsHeader",
                "rule": "Cluster 07: Table Header & Safe-Region Boundary Physics",
                "severity": "Critical",
                "analysis": "Fixed 123-character divider strings.Repeat('-', 123) causes severe line wrapping on standard 80/100-col terminals.",
                "action": "Constrain table width via termout.GetTerminalWidth() and use rounded Unicode box borders."
            },
            {
                "id": "064",
                "title": "Nodes Resources Table Width Discrepancy & Box Alignment",
                "file": "cli/cmdnodes/nodes_resources_table.go (`L28–L40`)",
                "func": "renderResourcesTable",
                "rule": "Cluster 07: Viewport Safe-Region Boundaries",
                "severity": "Major",
                "analysis": "Header border uses 116 characters while divider lines use 118 characters, creating jagged broken corners.",
                "action": "Normalize border widths to a single calculated constant with verified corner junctions."
            },
            {
                "id": "065",
                "title": "Pre-Flight Clone Table Asymmetric Box Borders Refactor",
                "file": "cli/cmdnodes/nodes_clone_table.go (`L36–L50`)",
                "func": "renderFleetPreFlightTable",
                "rule": "Cluster 07: Table Header & Sticky Border Materiality",
                "severity": "Major",
                "analysis": "Header box uses 104 hyphens while body table uses 100-col width, resulting in unaligned borders.",
                "action": "Unify pre-flight tables into a single termout.Table instance with consistent 100-column bounds."
            }
        ]
    },
    {
        "slot": 14,
        "items": "066–070",
        "domain": "Cluster 07: Sticky Header Persistence & Dynamic Window Resize (Part B)",
        "items_data": [
            {
                "id": "066",
                "title": "Clone List Table Border Color Empty String Bug & Header Anchors",
                "file": "cli/cmdclone/clone_list_table.go (`L35–L45`)",
                "func": "printCloneRecordsTable",
                "rule": "Cluster 07: Table Header & Sticky Border Materiality",
                "severity": "Critical",
                "analysis": "Table config initializes BorderColor to empty string (''), causing terminal emulators to omit border styling.",
                "action": "Explicitly set BorderColor to termout.RoleSGR(termout.RoleBorder) with rounded corners."
            },
            {
                "id": "067",
                "title": "ClonePick Bubbletea Viewport Scroll Indicator & Fixed Header",
                "file": "cli/clonepick/picker_view.go (`L25–L40`)",
                "func": "View",
                "rule": "Cluster 07: Scroll Indicators & Fixed Header Anchoring",
                "severity": "Major",
                "analysis": "When repo count exceeds terminal window height, items scroll off-screen without scroll indicators.",
                "action": "Anchor header and footer action bar while adding visual scrollbar indicators (▲ X more above, ▼ Y more below)."
            },
            {
                "id": "068",
                "title": "Dynamic Terminal Width Clamping in Safe Auto-Stash Header",
                "file": "cli/cmdfix/fix_stashing.go (`L13–L21`)",
                "func": "formatStashCardHeader",
                "rule": "Cluster 07: Dynamic Viewport Resize & Window Bounds",
                "severity": "Minor",
                "analysis": "Long repository names cause negative dash counts, forcing right corner ┐ to wrap onto a new line.",
                "action": "Truncate repo name to fit window width and clamp dash count to minimum of 2."
            },
            {
                "id": "069",
                "title": "Terminal Window Width Adaptation in Stash Card Box",
                "file": "cli/cmdfix/fix_stashing.go (`L90–L104`)",
                "func": "RenderHumanizedStashCard",
                "rule": "Cluster 07: Terminal Content Width Boundaries",
                "severity": "Minor",
                "analysis": "Hardcoded boxWidth := 72 clips on narrow mobile wrappers (60 cols) and looks cramped on ultra-wide screens.",
                "action": "Probe termout.GetTerminalWidth() and dynamically clamp box width to [48, 88] columns."
            },
            {
                "id": "070",
                "title": "Execution Card Border Alignment & Width Precision in Sends",
                "file": "cli/cmd/cmd_sends_render.go (`L34–L58`)",
                "func": "renderSendsTerminalSummary",
                "rule": "Cluster 07: Terminal Content Width Boundaries & Box Drawing",
                "severity": "Critical",
                "analysis": "Hardcoded column specifiers and unstripped ANSI escape sequences cause jagged right borders.",
                "action": "Calculate visual string width via termout.VisualStringWidth and pad all rows to exact 78 printable columns."
            }
        ]
    },
    {
        "slot": 15,
        "items": "071–075",
        "domain": "Cluster 08: 4px/8px Spacing Rhythm & 8-Column Alignment in Fleet/Scan (Part A)",
        "items_data": [
            {
                "id": "071",
                "title": "Fleet Nodes Task Queue Asymmetric Indentation Standardization",
                "file": "cli/cmdnodes/nodes_tasks_table.go (`L55–L70`)",
                "func": "renderFleetTasksTable",
                "rule": "Cluster 08: 8px / 1-Space Character Grid Alignment",
                "severity": "Major",
                "analysis": "Mixes 2-space, 4-space, and 6-space left margins across node titles, active tasks, and queued items.",
                "action": "Standardize on strict 2-character indent levels (Level 0: 0, Level 1: 2, Level 2: 4 spaces)."
            },
            {
                "id": "072",
                "title": "Fleet Remote Bootstrap Step Pad Rhythm & Separator Insets",
                "file": "cli/cmdnodes/nodes_bootstrap.go (`L300–L325`)",
                "func": "renderBootstrapDocket",
                "rule": "Cluster 08: Vertical Spacing Rhythm & Card Insets",
                "severity": "Minor",
                "analysis": "Vertical spacing between bootstrap stages varies between 0 and 2 blank lines, causing visual jitter.",
                "action": "Enforce single blank line separation between stage cards and consistent 2-space horizontal inset."
            },
            {
                "id": "073",
                "title": "Cloner Parallel Progress Baseline Alignment & Padding",
                "file": "cli/cloner/progress.go (`L50–L65`)",
                "func": "Done",
                "rule": "Cluster 08: Baseline Alignment & Metric Row Rhythm",
                "severity": "Minor",
                "analysis": "Completed progress row glyphs align unevenly due to variable duration string lengths.",
                "action": "Pad duration strings to fixed 8-character width (%8s) to align right border indicators."
            },
            {
                "id": "074",
                "title": "Batch Operations Box Summary Internal Padding Normalization",
                "file": "cli/cloner/batchprogress.go (`L150–L170`)",
                "func": "PrintSummary",
                "rule": "Cluster 08: Card Insets & Internal Box Padding",
                "severity": "Major",
                "analysis": "Summary box content touches top and bottom borders with zero vertical padding.",
                "action": "Add 1-line top and bottom insets inside summary card to conform to Tokyo Night card spacing."
            },
            {
                "id": "075",
                "title": "Reclone Disk Layout Tree Indentation & Depth Limit Bounds",
                "file": "cli/cmdclone/reclone_summary.go (`L75–L95`)",
                "func": "printSummaryTree",
                "rule": "Cluster 08: Hierarchy Tree Indent Step (4-Char Standard)",
                "severity": "Minor",
                "analysis": "Nested directory levels use inconsistent 3-character and 5-character indentation.",
                "action": "Standardize tree branch indentation on strict 4-character increments (├── , └── )."
            }
        ]
    },
    {
        "slot": 16,
        "items": "076–080",
        "domain": "Cluster 08: 4px/8px Spacing Rhythm & 8-Column Alignment in Fleet/Scan (Part B)",
        "items_data": [
            {
                "id": "076",
                "title": "Clone Pretty Runner Failure Panel Insets & Padding",
                "file": "cli/cmdclone/clonepretty.go (`L135–L155`)",
                "func": "printClonePrettyFailure",
                "rule": "Cluster 08: Card Insets & Internal Box Padding",
                "severity": "Major",
                "analysis": "Failure error output dumps directly against left terminal edge with zero margin.",
                "action": "Indent failure diagnostic panel by 4 spaces and frame with red error accent border."
            },
            {
                "id": "077",
                "title": "Constants Clone Pretty Format Strings Spacing Normalization",
                "file": "cli/constants/constants_clone_pretty.go (`L15–L35`)",
                "func": "MsgClonePrettyHeader",
                "rule": "Cluster 08: 8px / 1-Space Character Grid Alignment",
                "severity": "Minor",
                "analysis": "Format string constants contain hardcoded irregular tab and space mixtures.",
                "action": "Clean format strings to eliminate tab characters and enforce strict 2-space indentation rhythm."
            },
            {
                "id": "078",
                "title": "Terminal Repo Block Key-Value Margin Alignment",
                "file": "cli/render/repotermblock.go (`L130–L150`)",
                "func": "buildBlockBody",
                "rule": "Cluster 08: Baseline Alignment & Micro-Typography",
                "severity": "Minor",
                "analysis": "Key labels vary in length from 4 to 9 characters with irregular space padding.",
                "action": "Align all metadata values at column 16 with fixed-width label formatting."
            },
            {
                "id": "079",
                "title": "ClonePick Interactive Picker Action Bar Spacing Rhythm",
                "file": "cli/clonepick/picker_view.go (`L100–L115`)",
                "func": "View",
                "rule": "Cluster 08: Button & Action Bar Spacing (16px / 2-Space Rhythm)",
                "severity": "Minor",
                "analysis": "Action shortcuts ([Enter] Proceed, [Space] Toggle) are rendered as a dense run-on sentence.",
                "action": "Separate keyboard action pills with 3-space margins and clear boundary dividers."
            },
            {
                "id": "080",
                "title": "Scan Output Section Margin & Divider Rhythm Normalization",
                "file": "cli/formatter/terminal.go (`L155–L180`)",
                "func": "Terminal",
                "rule": "Cluster 08: Vertical Section Spacing (24px / 3-Line Standard)",
                "severity": "Major",
                "analysis": "Vertical spacing between banner, repository list, and tree view varies arbitrarily from 1 to 3 blank lines.",
                "action": "Enforce strict 1-blank-line rhythm between intra-card elements and 2-blank-line rhythm between major sections."
            }
        ]
    },
    {
        "slot": 17,
        "items": "081–085",
        "domain": "Cluster 09: CSS3 GPU Transitions & WAI-ARIA Modals in MCP/Repo Wizards (Part A)",
        "items_data": [
            {
                "id": "081",
                "title": "Web Dashboard HTML/GUI Endpoint Handler in MCP HTTP Server",
                "file": "cli/cmdmcp/http_server.go (`L106–L123`)",
                "func": "listenAndServeMcp",
                "rule": "Cluster 09: Embedded Web UI Dashboard & CSS3 GPU Acceleration",
                "severity": "Major",
                "analysis": "Opening MCP server port in a browser returns 404, lacking visual status inspection.",
                "action": "Register /dashboard and / routes serving an embedded responsive single-page visualizer with Tokyo Night styling."
            },
            {
                "id": "082",
                "title": "Live SSE Event Stream Endpoint for Dashboard Micro-Interactions",
                "file": "cli/cmdmcp/http_server.go (`L136–L150`)",
                "func": "handleHttpEvents",
                "rule": "Cluster 09: Real-Time Micro-Interactions & Hardware Transitions",
                "severity": "Major",
                "analysis": "Dashboard requires aggressive polling to detect server uptime and newly registered tools.",
                "action": "Implement /events Server-Sent Events endpoint broadcasting live heartbeats and tool telemetry."
            },
            {
                "id": "083",
                "title": "Elimination of Framer-Motion in React Projects Overview",
                "file": "src/pages/Projects.tsx (`L15–L40`)",
                "func": "Projects",
                "rule": "Cluster 09: Zero-Overhead CSS3 Transitions (Ban on Framer-Motion)",
                "severity": "Critical",
                "analysis": "Imports framer-motion causing unnecessary bundle weight and CPU layout reflows.",
                "action": "Replace framer-motion with zero-overhead Tailwind CSS3 GPU acceleration (transition-all duration-200 transform-gpu)."
            },
            {
                "id": "084",
                "title": "WAI-ARIA Button Roles & Keyboard Handlers on ProjectCard",
                "file": "src/components/projects/ProjectCard.tsx (`L25–L45`)",
                "func": "ProjectCard",
                "rule": "Cluster 09: WAI-ARIA Interactive Element Contracts",
                "severity": "Major",
                "analysis": "Clickable project card div lacks role='button', tabIndex, and onKeyDown keyboard accessibility handlers.",
                "action": "Add role='button', tabIndex={0}, and onKeyDown Enter/Space handlers with visible focus-visible ring."
            },
            {
                "id": "085",
                "title": "CSS Grid Animated Height Transitions in RepoGroup Accordion",
                "file": "src/components/projects/RepoGroup.tsx (`L30–L55`)",
                "func": "RepoGroup",
                "rule": "Cluster 09: GPU-Accelerated Height Transitions",
                "severity": "Minor",
                "analysis": "Uses JavaScript height interpolation causing layout recalculations during accordion open/close.",
                "action": "Implement modern CSS grid-template-rows [0fr -> 1fr] transition for 60fps GPU accordion animation."
            }
        ]
    },
    {
        "slot": 18,
        "items": "086–090",
        "domain": "Cluster 09: CSS3 GPU Transitions & WAI-ARIA Modals in MCP/Repo Wizards (Part B)",
        "items_data": [
            {
                "id": "086",
                "title": "WAI-ARIA Tab Semantics in ProjectMetricCards",
                "file": "src/components/projects/ProjectMetricCards.tsx (`L35–L55`)",
                "func": "ProjectMetricCards",
                "rule": "Cluster 09: WAI-ARIA Interactive Tablist Contracts",
                "severity": "Minor",
                "analysis": "Metric filter cards act as selectable tabs but lack role='tablist' and aria-selected state declarations.",
                "action": "Add role='tablist', role='tab', aria-selected={isActive}, and motion-reduce query safety."
            },
            {
                "id": "087",
                "title": "Dialog Contract & Positive Boolean in ProjectDetailDialog",
                "file": "src/components/projects/ProjectDetailDialog.tsx (`L15–L35`)",
                "func": "ProjectDetailDialog",
                "rule": "Cluster 09: WAI-ARIA Dialog Semantics & Positive Booleans",
                "severity": "Major",
                "analysis": "Props interface uses un-prefixed open boolean and dialog lacks aria-describedby accessibility contract.",
                "action": "Rename prop to isOpen and add aria-labelledby and aria-describedby linking to header and body."
            },
            {
                "id": "088",
                "title": "Terminal Spinner Micro-Interaction in runStashCreate",
                "file": "cli/cmdfix/fix_stashing.go (`L127–L138`)",
                "func": "runStashCreate",
                "rule": "Cluster 09: Standardized Transition Timings & Active Spinners",
                "severity": "Minor",
                "analysis": "On large codebases, git stash -u hangs for 5-15s with frozen cursor, confusing the operator.",
                "action": "Attach termout.StartProgressSpinner providing live tactile braille feedback during stashing."
            },
            {
                "id": "089",
                "title": "Lipgloss Card Formatting & Table Semantics in runTools",
                "file": "cli/cmdmcp/mcp_cmd.go (`L75–L87`)",
                "func": "runTools",
                "rule": "Cluster 09: Non-Blocking Focus & Structured Terminal Cards",
                "severity": "Minor",
                "analysis": "Direct raw fmt.Fprintf produces unbordered text that clips on narrow viewports.",
                "action": "Format tools catalog via termout.RenderTable with automatic column wrapping and headers."
            },
            {
                "id": "090",
                "title": "Screen Reader aria-live Region in RemovePreview Terminal Mock",
                "file": "src/pages/TempRelease.tsx (`L30–L51`)",
                "func": "RemovePreview",
                "rule": "Cluster 09: WAI-ARIA Live Telemetry & Assistive Feedback",
                "severity": "Minor",
                "analysis": "Simulated terminal output contains interactive prompts but lacks role='log' or aria-live announcements.",
                "action": "Wrap terminal mock in role='region' aria-label='Interactive Branch Removal Simulation' with role='log'."
            }
        ]
    },
    {
        "slot": 19,
        "items": "091–095",
        "domain": "Cluster 10: Frontend Hygiene <=80L & Flywheel Ledger Continuity (Part A)",
        "items_data": [
            {
                "id": "091",
                "title": "Deconstruct Monolithic FixRepo.tsx (143L) into Focused Micro-Components",
                "file": "src/pages/FixRepo.tsx (`L26–L65`)",
                "func": "FixRepoPage",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Major",
                "analysis": "143 lines long, bundling static data tables and overview cards directly into route file.",
                "action": "Extract to src/components/fixrepo/FixRepoOverviewCards.tsx, FixRepoFlagsTable.tsx, and FixRepoExitCodesTable.tsx."
            },
            {
                "id": "092",
                "title": "Deconstruct Monolithic BatchActions.tsx (332L) into Subtask Modules",
                "file": "src/pages/BatchActions.tsx (`L52–L110`)",
                "func": "BatchActions",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Critical",
                "analysis": "At 332 lines, file is 3x allowable limit, mixing workflow steps, card grids, and terminal demos.",
                "action": "Decompose into BatchActionsWorkflowSteps.tsx, BatchActionsCardGrid.tsx, and BatchActionsTerminalDemo.tsx."
            },
            {
                "id": "093",
                "title": "Massive Monolithic Decomposition of Release.tsx (663L)",
                "file": "src/pages/Release.tsx (`L1–L100`)",
                "func": "ReleasePage",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Critical",
                "analysis": "Single largest monolithic page (663 lines) containing 10 separate inline data tables and diagrams.",
                "action": "Deconstruct into 7 micro-components in src/components/release/ (Features, Flags, Bump, Zip, Errors, Layout, Pruning)."
            },
            {
                "id": "094",
                "title": "Deconstruct ReleaseAlias.tsx (167L) into Clean Subcomponents",
                "file": "src/pages/ReleaseAlias.tsx (`L15–L40`)",
                "func": "ReleaseAliasPage",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Major",
                "analysis": "Mixes command overview cards, flag tables, and safety net documentation into one 167-line file.",
                "action": "Decompose into ReleaseAliasWorkflowCards.tsx, ReleaseAliasFlagsTable.tsx, and ReleaseAliasSafetyNetSection.tsx."
            },
            {
                "id": "095",
                "title": "Modular Deconstruction of ClearReleaseJSON.tsx (239L)",
                "file": "src/pages/ClearReleaseJSON.tsx (`L39–L80`)",
                "func": "ClearReleaseJSONPage",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Major",
                "analysis": "Embeds four large data arrays (features, edgeCases, exitCodes, constants) and loops directly in page.",
                "action": "Decompose into ClearReleaseHeader.tsx, ClearReleaseFeaturesGrid.tsx, and ClearReleaseEdgeCasesTable.tsx."
            }
        ]
    },
    {
        "slot": 20,
        "items": "096–100",
        "domain": "Cluster 10: Frontend Hygiene <=80L & Flywheel Ledger Continuity (Part B)",
        "items_data": [
            {
                "id": "096",
                "title": "Deconstruct Monolithic RepoSettings.tsx (532L) into Sub-Panels",
                "file": "src/pages/RepoSettings.tsx (`L50–L120`)",
                "func": "RepoSettings",
                "rule": "Cluster 10: Component Length Cap <=80-100 Lines",
                "severity": "Critical",
                "analysis": "532 lines long, bundling general settings, remote sync, danger zones, and mutation hooks.",
                "action": "Decompose into RepoSettingsGeneral.tsx, RepoSettingsRemotes.tsx, RepoSettingsDangerZone.tsx, and useRepoSettings hook."
            },
            {
                "id": "097",
                "title": "Deconstruct ReleaseVersion.tsx (221L) & Negative Boolean Remediation",
                "file": "src/pages/ReleaseVersion.tsx (`L30–L70`)",
                "func": "ReleaseVersion",
                "rule": "Cluster 10: Component Length Cap & Positive Booleans",
                "severity": "Major",
                "analysis": "Violates 100-line limit and contains negative boolean disabled={!isValid} in button props.",
                "action": "Deconstruct into ReleaseVersionHeader.tsx and ReleaseVersionBumpMatrix.tsx; invert boolean to isSubmitDisabled={isInvalid}."
            },
            {
                "id": "098",
                "title": "Function Decomposition of RunMcp Command Dispatcher",
                "file": "cli/cmdmcp/mcp_cmd.go (`L15–L35`)",
                "func": "RunMcp",
                "rule": "Cluster 10: 15-Line Function Limit & Map Dispatch",
                "severity": "Minor",
                "analysis": "Spans 21 lines with chained switch-case matching subcommands, violating 15-line limit.",
                "action": "Refactor to map dispatch table mcpActionDispatch reducing dispatcher to <=8 lines."
            },
            {
                "id": "099",
                "title": "Categorized Tool Struct Schema Extension in MCP Protocol",
                "file": "cli/cmdmcp/protocol.go (`L28–L33`)",
                "func": "Tool",
                "rule": "Cluster 10: Dedicated TypeScript/Schema Interfaces",
                "severity": "Minor",
                "analysis": "Tool schema omits category, icon token, and read-only flags, forcing UI renderers to guess layout groupings.",
                "action": "Add Category, Icon, and IsReadOnly fields with omitempty tags for full backward compatibility."
            },
            {
                "id": "100",
                "title": "Flywheel Ledger Synchronization & Automated Sequence Registration",
                "file": ".ai-memory/improvements/flywheel.json (`L1–L45`)",
                "func": "FlywheelLedger",
                "rule": "Cluster 10: Strict Flywheel Ledger Continuity & Zero Duplicate Items",
                "severity": "Critical",
                "analysis": "Wave 4 execution requires atomic synchronization of flywheel.json with all 20 batch slots registered.",
                "action": "Record Wave 4 (403-improvements) completed planning state, 100 items verified, linking slots 01-20."
            }
        ]
    }
]

def render_slot_markdown(slot_info: dict) -> str:
    slot_num = slot_info["slot"]
    items_range = slot_info["items"]
    domain = slot_info["domain"]
    slot_str = f"{slot_num:02d}"

    lines = [
        f"# Subtask: 403-improvements — Slot {slot_str} (Items {items_range})",
        "",
        "- **Parent Spec**: `02-spec/21-app/403-improvements/01-architecture-spec.md`",
        "- **Parent Plan**: `.ai-memory/plans/pending/403-improvements.md`",
        f"- **Slot Index**: {slot_num} of 20 (Items {items_range})",
        f"- **Domain**: {domain}",
        "- **Status**: PENDING",
        "",
        "---",
        "",
        f"## Technical Deliverables Breakdown (Items {items_range})",
        ""
    ]

    for item in slot_info["items_data"]:
        lines.extend([
            f"### Item {item['id']} ({item['title']})",
            f"- **Target File**: `{item['file']}`",
            f"- **Target Function**: `{item['func']}`",
            f"- **Rule Violated**: {item['rule']}",
            f"- **Severity**: {item['severity']}",
            f"- **Analysis**: {item['analysis']}",
            f"- **Action**: {item['action']}",
            ""
        ])

    lines.extend([
        "---",
        "",
        "## Grounded Verification Contract",
        "",
        "1. **Zero-Build Constraint**: Do not execute compiler builds during planning wave.",
        "2. **Specification Conformance**: All proposed refactoring patterns must match 10-field specification schema in parent spec.",
        "3. **Relative Path Hygiene**: Use strictly relative markdown links and filesystem paths.",
        "4. **LF Line Endings**: Ensure zero CRLF line breaks.",
        ""
    ])

    return "\n".join(lines)

def main():
    os.makedirs(SUBTASKS_DIR, exist_ok=True)
    generated = 0
    for slot_info in SLOTS_DATA:
        slot_num = slot_info["slot"]
        items_range = slot_info["items"]
        slot_str = f"{slot_num:02d}"
        file_name = f"slot-{slot_str}-items-{items_range.replace('–', '-to-')}.md"
        file_path = os.path.join(SUBTASKS_DIR, file_name)

        content = render_slot_markdown(slot_info)
        with open(file_path, "w", encoding="utf-8", newline="\n") as f:
            f.write(content)
        generated += 1
        print(f"Generated: {file_name}")

    print(f"Total subtask slots generated: {generated}")

if __name__ == "__main__":
    main()
