<?php

function parse_todo_category_filter($raw = null): array
{
    if ($raw === null) {
        $raw = $_GET['category'] ?? null;
    }
    if ($raw === null || $raw === '' || $raw === 'all') {
        return ['all' => true, 'ids' => []];
    }

    $ids = [];
    foreach (explode(',', (string) $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    $ids = array_values($ids);
    if (empty($ids)) {
        return ['all' => true, 'ids' => []];
    }

    return ['all' => false, 'ids' => $ids];
}

function todo_category_filter_query_string(array $filter): string
{
    if ($filter['all']) {
        return '';
    }

    return 'category=' . implode(',', $filter['ids']);
}

function todo_category_sql_filter(array $filter, string $column = 't.category'): ?array
{
    if ($filter['all']) {
        return null;
    }

    $ids = $filter['ids'];
    if (count($ids) === 1) {
        return [
            'sql' => "$column = ?",
            'types' => 'i',
            'params' => [$ids[0]],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    return [
        'sql' => "$column IN ($placeholders)",
        'types' => str_repeat('i', count($ids)),
        'params' => $ids,
    ];
}

function render_todo_category_filter(array $categories, array $filter, string $pageFile, string $labelKey, string $allLabelKey): void
{
    $selectedIds = array_flip($filter['ids']);
    ?>
    <div class="todo-cat-filter" style="min-width:200px; flex:1;">
      <span class="sp-label"><?= t($labelKey) ?></span>
      <div class="todo-cat-filter-options" id="categoryFilterOptions">
        <label class="todo-cat-filter-option">
          <input type="checkbox" id="categoryFilterAll" <?php if ($filter['all']) echo 'checked'; ?>>
          <?= htmlspecialchars(t($allLabelKey)) ?>
        </label>
        <?php foreach ($categories as $categoryRow): ?>
          <?php
            $categoryId = (int) $categoryRow['id'];
            $checked = !$filter['all'] && isset($selectedIds[$categoryId]);
          ?>
          <label class="todo-cat-filter-option">
            <input type="checkbox" class="categoryFilterItem" value="<?= $categoryId ?>" <?php if ($checked) echo 'checked'; ?>>
            <?= htmlspecialchars($categoryRow['category']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function() {
      const pageFile = <?= json_encode($pageFile) ?>;
      const allCheckbox = document.getElementById('categoryFilterAll');
      const categoryCheckboxes = Array.from(document.querySelectorAll('.categoryFilterItem'));

      function navigateWithFilter() {
        const selectedIds = categoryCheckboxes
          .filter(function(cb) { return cb.checked; })
          .map(function(cb) { return cb.value; });

        if (allCheckbox.checked || selectedIds.length === 0) {
          window.location.href = pageFile;
          return;
        }

        window.location.href = pageFile + '?category=' + selectedIds.join(',');
      }

      allCheckbox.addEventListener('change', function() {
        if (allCheckbox.checked) {
          categoryCheckboxes.forEach(function(cb) { cb.checked = false; });
          navigateWithFilter();
        }
      });

      categoryCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
          if (cb.checked) {
            allCheckbox.checked = false;
          } else if (!categoryCheckboxes.some(function(item) { return item.checked; })) {
            allCheckbox.checked = true;
          }
          navigateWithFilter();
        });
      });
    })();
    </script>
    <?php
}