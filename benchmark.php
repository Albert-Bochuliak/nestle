<?php

/**
 * @file
 * Baseline benchmark for the glossary_tooltip module.
 *
 * Measures service-level phase timings, memory and HTTP TTFB across term
 * counts (100/1k/5k/10k), HTML sizes (5/25/50/100 KB) and match densities.
 * Uses synthetic terms (prefix "bzbench") and never touches real data.
 *
 * Usage (from the Drupal docroot):
 *   php modules/custom/glossary_tooltip/benchmark.php
 *   php modules/custom/glossary_tooltip/benchmark.php --tier=1000
 *   php modules/custom/glossary_tooltip/benchmark.php --cleanup
 *
 * Results are appended to benchmark-results.json in the docroot.
 */

declare(strict_types=1);

use Drupal\user\Entity\User;
use Drupal\glossary_tooltip\Service\GlossaryTermProvider;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\glossary_tooltip\Model\HighlightContext;
use Drupal\glossary_tooltip\Service\GlossaryMatcher;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;

$opts = getopt('', ['tier::', 'cleanup', 'components', 'matcher']);
$tier = isset($opts['tier']) ? (int) $opts['tier'] : NULL;

$autoload = require __DIR__ . '/../../../autoload.php';
$request = Request::create('http://nestle-test.loc/');
$kernel = DrupalKernel::createFromRequest($request, $autoload, 'prod');
$kernel->boot();
$kernel->preHandle($request);

if (isset($opts['cleanup'])) {
  bench_terms_cleanup();
  exit(0);
}

if (isset($opts['components'])) {
  bench_components();
  exit(0);
}

if (isset($opts['matcher'])) {
  bench_matcher();
  exit(0);
}

bench_run($tier);

/**
 * Prints environment info, runs the scenario matrix, writes JSON results.
 *
 * @param int|null $only_tier
 *   Run a single term-count tier, or all tiers when NULL.
 */
function bench_run(?int $only_tier): void {
  printf("env: %s\n", json_encode(bench_env(), JSON_UNESCAPED_SLASHES));

  $tiers = [100, 1000, 2332, 2333, 5000, 10000];
  foreach ($tiers as $count) {
    if ($only_tier !== NULL && $only_tier !== $count) {
      continue;
    }
    try {
      bench_terms_ensure($count);
      bench_tier($count);
      bench_persist(['tier' => $count, 'status' => 'ok']);
    }
    catch (\Throwable $e) {
      bench_persist(['tier' => $count, 'status' => 'failed', 'error' => $e->getMessage()]);
      printf("TIER %d FAILED: %s\n", $count, $e->getMessage());
      break;
    }
  }

  if ($only_tier === NULL) {
    bench_terms_cleanup();
  }
  echo "results appended to ", 'benchmark-results.json', "\n";
}

/**
 * Environment snapshot for the report.
 *
 * @return array<string, mixed>
 *   PHP/PCRE/Drupal configuration facts.
 */
function bench_env(): array {
  return [
    'php' => PHP_VERSION,
    'os' => PHP_OS_FAMILY,
    'memory_limit' => ini_get('memory_limit'),
    'pcre.backtrack_limit' => ini_get('pcre.backtrack_limit'),
    'pcre.jit' => ini_get('pcre.jit'),
    'opcache' => extension_loaded('Zend OPcache'),
    'drupal' => \Drupal::VERSION,
  ];
}

/**
 * Creates synthetic terms until at least $target exist.
 *
 * @param int $target
 *   Desired number of synthetic terms (real terms add ~11 more).
 */
function bench_terms_ensure(int $target): void {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $have = bench_term_count();
  if ($have >= $target) {
    return;
  }

  $t0 = hrtime(TRUE);
  for ($i = $have; $i < $target; $i++) {
    $name = 'bzbench' . sprintf('%05d', $i);
    if ($i % 50 === 49) {
      $name = 'слово' . sprintf('%05d', $i);
    }
    elseif ($i % 20 === 19) {
      $name = 'bzbench' . sprintf('%05d', $i) . ' multi word';
    }
    Term::create([
      'vid' => 'glossary',
      'name' => $name,
      // plain_text format keeps term descriptions out of the filter
      // pipeline: 'processed' on a format containing glossary_tooltip
      // re-enters the whole highlight path recursively.
      'description' => [
        'value' => str_repeat('bench description ', 5) . $i,
        'format' => 'plain_text',
      ],
      'status' => 1,
    ])->save();

    // Keep the entity memory cache from ballooning during bulk creation.
    if ($i % 500 === 499) {
      \Drupal::service('entity.memory_cache')->deleteAll();
      $storage->resetCache();
      gc_collect_cycles();
    }
  }
  printf("created %d terms in %.1fs (total synthetic: %d)\n",
    $target - $have, (hrtime(TRUE) - $t0) / 1e9, $target);
}

/**
 * Deletes all synthetic benchmark terms.
 */
function bench_terms_cleanup(): void {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('vid', 'glossary')
    ->condition('name', 'bzbench%', 'LIKE')
    ->execute();
  foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
    $storage->delete($storage->loadMultiple($chunk));
    \Drupal::service('entity.memory_cache')->deleteAll();
    $storage->resetCache();
  }
  // Unicode synthetic names do not match the prefix; remove them too.
  $ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('vid', 'glossary')
    ->condition('name', 'слово%', 'LIKE')
    ->execute();
  $storage->delete($storage->loadMultiple($ids));
  printf("cleanup: %d terms deleted\n", count($ids));
}

/**
 * Counts synthetic benchmark terms.
 *
 * @return int
 *   Number of synthetic terms present.
 */
function bench_term_count(): int {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  return (int) $storage->getQuery()->accessCheck(FALSE)
    ->condition('vid', 'glossary')
    ->condition('name', 'bzbench%', 'LIKE')
    ->count()->execute()
    + (int) $storage->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'glossary')
      ->condition('name', 'слово%', 'LIKE')
      ->count()->execute();
}

/**
 * Runs the measurement matrix for one term-count tier.
 *
 * @param int $count
 *   Synthetic term count for this tier.
 */
function bench_tier(int $count): void {
  $out = ['terms' => $count];

  // ---- Provider -------------------------------------------------------
  // Cold: entity cache emptied, fresh provider instance.
  $out['provider_cold'] = bench_measure(function () {
    \Drupal::cache('entity')->deleteAll();
    $provider = new GlossaryTermProvider(
      \Drupal::entityTypeManager(),
      \Drupal::service('entity.repository'),
    );
    return $provider->getTerms('en');
  }, 3);

  // Warm: entity cache hot, fresh provider (memoization not hit).
  $out['provider_warm'] = bench_measure(static function () {
    $provider = new GlossaryTermProvider(
      \Drupal::entityTypeManager(),
      \Drupal::service('entity.repository'),
    );
    return $provider->getTerms('en');
  }, 10);

  $entry = \Drupal::service('glossary_tooltip.term_provider')->getTerms('en');
  $terms = $entry['terms'];
  $names = array_column($terms, 'name');

  // Estimated sub-phase costs inside the provider load (replicated ops).
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $entities = $storage->loadMultiple(array_keys($terms));
  $anonymous = User::getAnonymousUser();

  $t = hrtime(TRUE);
  foreach ($entities as $term) {
    $term->access('view', $anonymous, TRUE);
    $term->get('name')->access('view', $anonymous, TRUE);
  }
  $out['est_access_ms'] = round((hrtime(TRUE) - $t) / 1e6, 2);

  $t = hrtime(TRUE);
  foreach ($entities as $term) {
    $field = $term->get('description');
    (string) ($field->processed ?? $field->value ?? '');
  }
  $out['est_description_ms'] = round((hrtime(TRUE) - $t) / 1e6, 2);

  $t = hrtime(TRUE);
  foreach ($entities as $term) {
    $term->toUrl()->toString(TRUE);
  }
  $out['est_url_ms'] = round((hrtime(TRUE) - $t) / 1e6, 2);

  // DB footprint of a cold provider load.
  Database::startLog('bench');
  \Drupal::cache('entity')->deleteAll();
  $provider = new GlossaryTermProvider(
    \Drupal::entityTypeManager(),
    \Drupal::service('entity.repository'),
  );
  $provider->getTerms('en');
  $log = Database::getLog('bench');
  $out['provider_queries'] = [
    'count' => count($log),
    'time_ms' => round(array_sum(array_column($log, 'time')) * 1000, 2),
  ];

  // ---- Matcher / context ---------------------------------------------
  $matcher = new GlossaryMatcher();
  $out['pattern_compile'] = bench_measure(
    static fn() => $matcher->compile($names),
    10,
  );
  $patterns = $matcher->compile($names);
  $out['pattern_count'] = $patterns === NULL ? 0 : count($patterns);
  $out['pattern_len_kb'] = $patterns === NULL ? 0 : round(array_sum(array_map('strlen', $patterns)) / 1024, 1);

  $out['context_build'] = bench_measure(
    static fn() => new HighlightContext($terms, TRUE, $matcher),
    20,
  );

  // ---- HTML corpus -----------------------------------------------------
  $sizes = [5, 25, 50, 100];
  foreach (['none', 'few', 'many'] as $density) {
    foreach ($sizes as $kb) {
      if ($density !== 'few' && !in_array($kb, [5, 100], TRUE)) {
        continue;
      }
      $html = bench_make_html($kb * 1024, $density, $names);
      $key = "html_{$kb}kb_{$density}";

      $out["{$key}_highlight"] = bench_measure(
        static fn() => \Drupal::service('glossary_tooltip.highlighter')->highlight($html, 'en'),
        5,
      );
      $out["{$key}_process"] = bench_measure(
        static fn() => \Drupal::service('glossary_tooltip.dom_processor')->process(
          $html,
          new HighlightContext($terms, TRUE, $matcher),
        ),
        5,
      );
      if ($density === 'few') {
        $out["{$key}_process_every"] = bench_measure(
          static fn() => \Drupal::service('glossary_tooltip.dom_processor')->process(
            $html,
            new HighlightContext($terms, FALSE, $matcher),
          ),
          5,
        );
      }
      $out["{$key}_parse"] = bench_measure(static fn() => Html::load($html), 10);
      $out["{$key}_serialize"] = bench_measure(
        static fn() => Html::serialize(Html::load($html)),
        5,
      );

      // Isolated regex cost on the plain text payload.
      $text = strip_tags($html);
      $out["{$key}_regex"] = $patterns === NULL ? NULL : bench_measure(
        static fn() => $matcher->findMatches($text, $patterns),
        10,
      );
      $out["{$key}_peak_mem"] = bench_peak(static fn() => \Drupal::service('glossary_tooltip.highlighter')->highlight($html, 'en'));
    }
  }

  // ---- Multi-field per request ----------------------------------------
  foreach ([1, 5, 10] as $fields) {
    $htmls = array_fill(0, $fields, bench_make_html(25 * 1024, 'few', $names));
    $out["fields_{$fields}"] = bench_measure(static function () use ($htmls) {
      foreach ($htmls as $html) {
        \Drupal::service('glossary_tooltip.highlighter')->highlight($html, 'en');
      }
    }, 5);
  }

  $out['terms_peak_mem'] = bench_peak(static function () {
    $provider = new GlossaryTermProvider(
      \Drupal::entityTypeManager(),
      \Drupal::service('entity.repository'),
    );
    $provider->getTerms('en');
  });

  $out['http'] = bench_http($names);

  bench_persist($out);
}

/**
 * Measures HTTP TTFB for a rendered node containing glossary matches.
 *
 * @param string[] $names
 *   Term names eligible for matching.
 *
 * @return array<string, mixed>
 *   Cold and warm TTFB stats in milliseconds.
 */
function bench_http(array $names): array {
  $node = Node::create([
    'type' => 'page',
    'title' => 'bench',
    'field_editor' => [
      'value' => bench_make_html(50 * 1024, 'few', $names),
      'format' => 'basic_html',
    ],
    'status' => 1,
  ]);
  $node->save();
  $url = 'http://nestle-test.loc/node/' . $node->id();

  $ttfb = static function () use ($url): float {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_HEADER => TRUE]);
    curl_exec($ch);
    $t = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000;
    return round($t, 1);
  };

  // Cold: page/render caches emptied before each request.
  $cold = [];
  for ($i = 0; $i < 3; $i++) {
    \Drupal::cache('render')->deleteAll();
    \Drupal::cache('page')->deleteAll();
    \Drupal::cache('dynamic_page_cache')->deleteAll();
    $cold[] = $ttfb();
  }

  $warm = [];
  for ($i = 0; $i < 20; $i++) {
    $warm[] = $ttfb();
  }
  sort($cold);
  sort($warm);
  $node->delete();

  $p = static function (array $times, float $pct): float {
    $n = count($times);
    return $times[max(0, (int) ceil($pct / 100 * $n) - 1)];
  };
  return [
    'cold_ms' => $cold,
    'warm_p50_ms' => $p($warm, 50),
    'warm_p95_ms' => $p($warm, 95),
    'warm_p99_ms' => $p($warm, 99),
  ];
}

/**
 * Measures matcher/context/DOM scaling without the term provider.
 *
 * The provider path is measured separately in bench_tier(): at large term
 * counts it cannot run at all (see BENCHMARK_BASELINE.md), so downstream
 * stages are profiled here with a synthesized term array.
 */
function bench_components(): void {
  $all = \Drupal::database()
    ->query("SELECT tid, name FROM {taxonomy_term_field_data} WHERE vid = 'glossary' AND status = 1 ORDER BY tid")
    ->fetchAllKeyed();

  foreach ([100, 1000, 5000, 10000] as $count) {
    $subset = array_slice($all, 0, $count, TRUE);
    if (count($subset) < $count) {
      continue;
    }
    // Synthetic term payload identical in shape to the provider's output.
    $terms = [];
    foreach ($subset as $tid => $name) {
      $terms[(int) $tid] = [
        'name' => $name,
        'description' => 'd',
        'url' => '/taxonomy/term/' . $tid,
      ];
    }
    unset($subset);

    $out = ['components_terms' => count($terms)];
    $matcher = new GlossaryMatcher();
    $term_names = array_column($terms, 'name');

    $out['pattern_compile'] = bench_measure(
      static fn() => $matcher->compile($term_names),
      10,
    );
    $patterns = $matcher->compile($term_names);
    $out['pattern_count'] = $patterns === NULL ? 0 : count($patterns);
    $out['pattern_len_kb'] = $patterns === NULL ? 0 : round(array_sum(array_map('strlen', $patterns)) / 1024, 1);
    $out['pattern_max_kb'] = $patterns === NULL ? 0 : round(max(array_map('strlen', $patterns)) / 1024, 1);

    $out['context_build'] = bench_measure(
      static fn() => new HighlightContext($terms, TRUE, $matcher),
      10,
    );

    foreach ([5, 100] as $kb) {
      foreach (['few', 'many'] as $density) {
        $html = bench_make_html($kb * 1024, $density, $term_names);
        $key = "html_{$kb}kb_{$density}";
        try {
          $out["{$key}_process"] = bench_measure(
            static fn() => \Drupal::service('glossary_tooltip.dom_processor')->process(
              $html,
              new HighlightContext($terms, TRUE, $matcher),
            ),
            5,
          );
        }
        catch (\Throwable $e) {
          $out["{$key}_process"] = 'FAIL: ' . $e->getMessage();
        }
        $text = strip_tags($html);
        try {
          $out["{$key}_regex"] = $patterns === NULL ? NULL : bench_measure(
            static fn() => $matcher->findMatches($text, $patterns),
            10,
          );
        }
        catch (\Throwable $e) {
          $out["{$key}_regex"] = 'FAIL: ' . $e->getMessage();
        }
      }
    }
    $out['peak_mem_mb'] = round(memory_get_peak_usage() / 1048576, 1);
    bench_persist($out);
    printf("components %d done (peak %.0fMB)\n", $count, $out['peak_mem_mb']);
  }
}

/**
 * Measures the matcher in isolation across PCRE-limit boundary tiers.
 *
 * Term names are generated (no entities), so TermProvider cost cannot
 * distort the numbers. Covers both sides of the previously measured
 * ~2 333-alternative PCRE failure point and verifies that chunked mode
 * still produces real tooltips end to end.
 */
function bench_matcher(): void {
  printf("env: %s\n", json_encode(bench_env(), JSON_UNESCAPED_SLASHES));

  $matcher = new GlossaryMatcher();
  foreach ([100, 1000, 2332, 2333, 5000, 10000] as $count) {
    // Deterministic mix of single-word, multi-word and Unicode names.
    $names = ['wet food', 'a very long multi word glossary term entry'];
    for ($i = 3; $i <= $count; $i++) {
      $names[] = match ($i % 50) {
        0 => 'multi word term ' . $i,
        25 => 'термін ' . $i,
        default => 'benchterm' . $i,
      };
    }

    $out = ['matcher_terms' => $count];
    $out['pattern_compile'] = bench_measure(
      static fn() => $matcher->compile($names),
      10,
    );
    $patterns = $matcher->compile($names);
    $out['pattern_count'] = $patterns === NULL ? 0 : count($patterns);
    $out['pattern_len_kb'] = $patterns === NULL ? 0 : round(array_sum(array_map('strlen', $patterns)) / 1024, 1);
    $out['pattern_max_kb'] = $patterns === NULL ? 0 : round(max(array_map('strlen', $patterns)) / 1024, 1);

    // Regex + match resolution on a 100 KB payload with matches present.
    $html = bench_make_html(100 * 1024, 'many', $names);
    $text = strip_tags($html);
    try {
      $out['regex_100kb'] = $patterns === NULL ? NULL : bench_measure(
        static fn() => $matcher->findMatches($text, $patterns),
        10,
      );
      $found = $patterns === NULL ? 0 : count($matcher->findMatches($text, $patterns));
      $out['regex_matches'] = $found;
    }
    catch (\Throwable $e) {
      $out['regex_100kb'] = 'FAIL: ' . $e->getMessage();
    }

    // End-to-end proof: real tooltips must appear, not a fail-safe passthrough.
    $terms = [];
    foreach ($names as $id => $name) {
      $terms[$id + 1] = [
        'name' => $name,
        'description' => 'd',
        'url' => '/taxonomy/term/' . ($id + 1),
      ];
    }
    try {
      $small = '<p>A wet food dish.</p>';
      $result = \Drupal::service('glossary_tooltip.dom_processor')->process(
        $small,
        new HighlightContext($terms, TRUE, $matcher),
      );
      $out['tooltip_rendered'] = $result !== NULL && str_contains($result, 'glossary-term');
    }
    catch (\Throwable $e) {
      $out['tooltip_rendered'] = 'FAIL: ' . $e->getMessage();
    }

    $out['peak_mem_mb'] = round(memory_get_peak_usage() / 1048576, 1);
    bench_persist($out);
    printf("matcher %d done (peak %.0fMB)\n", $count, $out['peak_mem_mb']);
  }
}

/**
 * Times a callable over adaptive iterations; returns percentile stats.
 *
 * @param callable $fn
 *   The operation under test.
 * @param int $target_iters
 *   Preferred iteration count.
 *
 * @return array<string, float|int>
 *   Iterations, p50/p95/p99/max in milliseconds.
 */
function bench_measure(callable $fn, int $target_iters): array {
  $fn();
  $times = [];
  $deadline = hrtime(TRUE) + (int) 20e9;
  for ($i = 0; $i < $target_iters; $i++) {
    $t = hrtime(TRUE);
    $fn();
    $times[] = (hrtime(TRUE) - $t) / 1e6;
    if (hrtime(TRUE) > $deadline || $times[0] > 5000) {
      break;
    }
  }
  sort($times);
  $n = count($times);
  $p = static fn(float $pct): float => $times[max(0, (int) ceil($pct / 100 * $n) - 1)];
  return [
    'iters' => $n,
    'p50_ms' => round($p(50), 2),
    'p95_ms' => round($p(95), 2),
    'p99_ms' => round($p(99), 2),
    'max_ms' => round($times[$n - 1], 2),
  ];
}

/**
 * Measures peak memory for one call.
 *
 * @param callable $fn
 *   The operation under test.
 *
 * @return float
 *   Peak memory in megabytes.
 */
function bench_peak(callable $fn): float {
  gc_collect_cycles();
  memory_reset_peak_usage();
  $fn();
  return round(memory_get_peak_usage() / 1048576, 1);
}

/**
 * Appends a result record to the JSON results file.
 *
 * @param array<string, mixed> $record
 *   The record to append.
 */
function bench_persist(array $record): void {
  $file = dirname(__DIR__, 3) . '/benchmark-results.json';
  $data = file_exists($file) ? (json_decode(file_get_contents($file) ?: '[]', TRUE) ?: []) : [];
  $data[] = $record;
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Builds synthetic HTML of roughly $bytes size at a match density.
 *
 * @param int $bytes
 *   Approximate byte size of the produced markup.
 * @param string $density
 *   One of 'none' (no terms), 'few' (~0.5% of words), 'many' (~10%).
 * @param string[] $names
 *   Term names eligible for matching.
 *
 * @return string
 *   Generated markup.
 */
function bench_make_html(int $bytes, string $density, array $names): string {
  static $vocab = [
    'lorem', 'ipsum', 'dolor', 'amet', 'consectetur', 'adipiscing', 'elit',
    'sed', 'eiusmod', 'tempor', 'incididunt', 'labore', 'dolore', 'magna',
    'aliqua', 'enim', 'minim', 'veniam', 'quis', 'nostrud',
  ];
  mt_srand(42);

  $html = '';
  $insert_every = match ($density) {
    'none' => PHP_INT_MAX,
    'few' => 400,
    'many' => 12,
    default => throw new \InvalidArgumentException('Unknown density'),
  };
  $words = 0;
  $picked = $names ? array_rand($names, min(50, count($names))) : [];

  while (strlen($html) < $bytes) {
    $sentence = [];
    for ($i = 0, $len = mt_rand(8, 15); $i < $len; $i++) {
      $words++;
      if ($words % $insert_every === 0 && $picked !== []) {
        $key = is_array($picked) ? $picked[array_rand($picked)] : $picked;
        $sentence[] = $names[$key];
      }
      else {
        $sentence[] = $vocab[array_rand($vocab)];
      }
    }
    $html .= '<p>' . implode(' ', $sentence) . '</p>';
  }
  return $html;
}
