<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\PhraseCategory;
use OCA\OpenCase\Db\PhraseCategoryMapper;
use OCA\OpenCase\Db\PhraseMapper;
use OCP\IL10N;

/**
 * Manages the Brevdesigner phrase categories (opencase_phrase_categories).
 * Categories form a tree via parent_id; a category can only be deleted when
 * it has neither sub-categories nor phrases.
 */
class PhraseCategoryService {

    public function __construct(
        private PhraseCategoryMapper $categoryMapper,
        private PhraseMapper $phraseMapper,
        private IL10N $l10n,
    ) {
    }

    /**
     * @return PhraseCategory[]
     */
    public function list(): array {
        return $this->categoryMapper->findAll();
    }

    /**
     * Categories in tree order — parents before children, siblings by
     * sort_order, then name (as the category picker in OpenCase) — each
     * with its depth and full path, e.g. "Afgørelser › Afslag". A category
     * whose parent is missing counts as top level.
     *
     * @return list<array{category: PhraseCategory, depth: int, path: string}>
     */
    public function tree(): array {
        $categories = $this->list();
        $ids = [];
        foreach ($categories as $c) {
            $ids[$c->getId()] = true;
        }
        $children = [];
        foreach ($categories as $c) {
            $parent = $c->getParentId() !== null && isset($ids[$c->getParentId()]) ? $c->getParentId() : 0;
            $children[$parent][] = $c;
        }

        $result = [];
        $seen   = [];
        $walk = function (int $parent, int $depth, string $prefix) use (&$walk, &$result, &$seen, $children): void {
            $list = $children[$parent] ?? [];
            usort($list, fn(PhraseCategory $a, PhraseCategory $b) => ($a->getSortOrder() <=> $b->getSortOrder()) ?: strcoll($a->getName(), $b->getName()));
            foreach ($list as $c) {
                if (isset($seen[$c->getId()])) {
                    continue;
                }
                $seen[$c->getId()] = true;
                $path = $prefix === '' ? $c->getName() : $prefix . ' › ' . $c->getName();
                $result[] = ['category' => $c, 'depth' => $depth, 'path' => $path];
                $walk($c->getId(), $depth + 1, $path);
            }
        };
        $walk(0, 0, '');
        return $result;
    }

    /**
     * @return array<int, string> category id => full path, e.g. "Afgørelser › Afslag"
     */
    public function paths(): array {
        $paths = [];
        foreach ($this->tree() as $row) {
            $paths[$row['category']->getId()] = $row['path'];
        }
        return $paths;
    }

    /**
     * @return array<int, int> phrase count per category id
     */
    public function phraseCounts(): array {
        return $this->phraseMapper->countByCategory();
    }

    public function get(int $id): PhraseCategory {
        $category = $this->categoryMapper->find($id);
        if ($category === null) {
            throw new NotFoundException("Phrase category {$id} not found");
        }
        return $category;
    }

    public function create(string $name, ?string $description, ?int $parentId, int $sortOrder, ?string $userId): PhraseCategory {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException($this->l10n->t('Navn skal udfyldes'));
        }
        if ($parentId !== null) {
            $this->get($parentId);
        }

        $category = new PhraseCategory();
        $category->setName($name);
        $category->setDescription($this->nullIfBlank($description));
        $category->setParentId($parentId);
        $category->setSortOrder($sortOrder);
        $category->setCreatedAt(new \DateTime());
        $category->setCreatedBy($userId);
        $category->setUpdatedAt(new \DateTime());
        $category->setUpdatedBy($userId);
        return $this->categoryMapper->insert($category);
    }

    /**
     * Apply the keys present in $changes (name, description, parent_id, sort_order).
     */
    public function update(int $id, array $changes, ?string $userId): PhraseCategory {
        $category = $this->get($id);

        if (array_key_exists('name', $changes)) {
            $name = trim((string)$changes['name']);
            if ($name === '') {
                throw new \InvalidArgumentException($this->l10n->t('Navn skal udfyldes'));
            }
            $category->setName($name);
        }
        if (array_key_exists('description', $changes)) {
            $category->setDescription($this->nullIfBlank($changes['description']));
        }
        if (array_key_exists('parent_id', $changes)) {
            $parentId = $changes['parent_id'] === null || $changes['parent_id'] === '' ? null : (int)$changes['parent_id'];
            if ($parentId !== null) {
                $this->get($parentId);
                if ($this->isSelfOrDescendant($parentId, $id)) {
                    throw new \InvalidArgumentException($this->l10n->t('En kategori kan ikke placeres under sig selv eller en af sine underkategorier'));
                }
            }
            $category->setParentId($parentId);
        }
        if (array_key_exists('sort_order', $changes)) {
            $category->setSortOrder((int)$changes['sort_order']);
        }
        $category->setUpdatedAt(new \DateTime());
        $category->setUpdatedBy($userId);
        return $this->categoryMapper->update($category);
    }

    public function delete(int $id): void {
        $category = $this->get($id);
        if ($this->categoryMapper->countChildren($id) > 0) {
            throw new \InvalidArgumentException($this->l10n->t('Kategorien har underkategorier og kan ikke slettes'));
        }
        if (($this->phraseMapper->countByCategory()[$id] ?? 0) > 0) {
            throw new \InvalidArgumentException($this->l10n->t('Kategorien indeholder fraser og kan ikke slettes'));
        }
        $this->categoryMapper->delete($category);
    }

    /**
     * Whether $candidateId is $categoryId itself or lies below it in the tree.
     */
    private function isSelfOrDescendant(int $candidateId, int $categoryId): bool {
        $parents = [];
        foreach ($this->categoryMapper->findAll() as $c) {
            $parents[$c->getId()] = $c->getParentId();
        }
        $seen = [];
        for ($current = $candidateId; $current !== null && !isset($seen[$current]); $current = $parents[$current] ?? null) {
            if ($current === $categoryId) {
                return true;
            }
            $seen[$current] = true;
        }
        return false;
    }

    private function nullIfBlank(mixed $value): ?string {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
