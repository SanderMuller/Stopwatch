---
layout: home

hero:
  name: Stopwatch
  text: Profiling for PHP and Laravel
  tagline: Mark the path, read the gaps. Checkpoints, query, memory and HTTP tracking, and a persistent run log — without standing up an APM.
  actions:
    - theme: brand
      text: Why Stopwatch?
      link: /why-stopwatch
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: GitHub
      link: https://github.com/SanderMuller/Stopwatch

features:
  - title: Checkpoints
    details: Mark points along a slow path and read the time between them, with query, memory and outbound-HTTP metrics attached.
    link: /checkpoints
  - title: Read it anywhere
    details: A self-contained HTML card, an injected toolbar, Server-Timing headers in DevTools, or a Debugbar timeline.
    link: /html-report
  - title: Run log
    details: Every finished run on disk, crashes included, so a slow request can be read after the fact instead of reproduced.
    link: /run-log
---

## The report

`@stopwatch` renders a run as a self-contained card: every checkpoint, its share of the total, and the queries behind it. Every style is inline, so it drops into any page or email body.

![The Stopwatch HTML report, listing checkpoints with their duration, share of the run and query counts](../rendered-stopwatch.png)

[More on the HTML report](06-html-report.md)
