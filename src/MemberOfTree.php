<?php

namespace Drupal\memberof_tree;

use Drupal\Core\Url;

/**
 * Implements a member_of tree.
 */
class MemberOfTree implements MemberOfTreeInterface {

  protected $node;

  protected $parentField;

  protected $weightField;

  /**
   * Constructs a MemberOfTree object.
   */
  public function __construct(int $nid) {
    // We at least need to load the entity given to get the bundle config.
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $this->node = $node_storage->load($nid);
    $bundle = $this->node->bundle();

    // Parent and weight fields.
    $this->parentField = 'field_member_of';
    $this->weightField = 'field_weight';
    $config = \Drupal::config('memberof_tree.settings');
    if (isset($config)) {
      foreach ($config->get('bundle_parent_fields') as $mapping) {
        if ($mapping['bundle'] == $bundle) {
          if (isset($mapping['parent_field'])) {
            $this->parentField = $mapping['parent_field'];
          }
          if (isset($mapping['weight_field'])) {
            $this->weightField = $mapping['weight_field'];
          }
        }
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function getParents($load_entities = FALSE) {
    $parents = $this->loadParents($this->node->id());
    if ($load_entities) {
      return $node_storage->loadMultiple(array_column($parents, 'nid'));
    }
    else {
      return $parents;
    }
  }

  /**
   * Internal function for recusively loading parent items.
   */
  private function loadParents($nid) {
    $parent = $this->loadParent($nid);
    if ($parent) {
      $parent['url'] = Url::fromRoute('entity.node.canonical',
        ['node' => $parent['nid']],
        ['absolute' => TRUE]
      );
      return array_merge($this->loadParents($parent['nid']), [$parent]);
    }
    // No parents to return.
    return [];
  }

  /**
   * Internal function for querying a node's parent based on their node ID.
   */
  private function loadParent($nid) {
    // SELECT n.nid, n.title
    // FROM node_field_data AS n
    // INNER JOIN node__field_as_parent AS f
    // ON f.field_as_parent_target_id = n.nid
    // WHERE f.entity_id = 10;.
    $parent_query = \Drupal::database()->select('node_field_data', 'n');
    $parent_query->join('node__' . $this->parentField, 'f', 'n.nid = f.' . $this->parentField . '_target_id');
    $parent_query->fields('n', ['nid', 'title'])
      ->condition('f.entity_id', $nid);
    return $parent_query->execute()->fetchAssoc();
  }

  /**
   * {@inheritdoc}
   */
  public function getChildren($max_depth = NULL, $load_entities = FALSE) {
    return $this->loadChildren($this->node->id(), $max_depth);
  }

  /**
   * Internal function for recusively loading child items up to a max depth.
   */
  private function loadChildren($nid, $max_depth = NULL) {
    // SELECT n.nid, n.title
    // FROM node_field_data AS n
    // INNER JOIN node__field_as_parent AS f ON f.entity_id = n.nid
    // INNER JOIN node__field_as_weight AS w ON n.nid = w.entity_id
    // WHERE f.field_as_parent_target_id = 125
    // ORDER BY w.field_as_weight_value, n.title.
    $child_query = \Drupal::database()->select('node_field_data', 'n');
    $child_query->join('node__' . $this->parentField, 'p', 'n.nid = p.entity_id');
    $child_query->join('node__' . $this->weightField, 'w', 'n.nid = w.entity_id');
    $child_query->fields('n', ['nid', 'title'])
      ->fields('w', [$this->weightField . '_value'])
      ->condition('p.' . $this->parentField . '_target_id', $nid)
      ->orderBy('w.' . $this->weightField . '_value');
    $results = $child_query->execute();
    $children = [];
    foreach ($results->fetchAllAssoc('nid', \PDO::FETCH_ASSOC) as $child_nid => $child) {
      // URL.
      $child['url'] = Url::fromRoute('entity.node.canonical',
        ['node' => $child['nid']],
        ['absolute' => TRUE]
      );
      $grand_children = [];
      if ($max_depth === NULL) {
        $grand_children = $this->loadChildren($child['nid'], NULL);
      }
      elseif ($max_depth > 1) {
        $next_depth = $max_depth - 1;
        $grand_children = $this->loadChildren($child['nid'], $next_depth);
      }
      if ($grand_children) {
        $child['below'] = $grand_children;
      }
      $children[] = $child;
    }
    return $children;
  }

  /**
   * {@inheritdoc}
   */
  public function getNext() {
    return $this->loadNext($this->node->id());
  }

  /**
   * Load the next node for the provided node ID.
   */
  private function loadNext($nid) {
    // Try to find a child (Depth-first tree traversal).
    $children = $this->loadChildren($nid, 1);
    if ($children) {
      return $children[0];
    }

    // Try to find a sibling.
    $next = FALSE;
    $parent = $this->loadParent($nid);
    // No Parent => No Sibling.
    if (empty($parent)) {
      return FALSE;
    }
    foreach ($this->loadChildren($parent['nid'], 1) as $sibling) {
      if ($next) {
        return $sibling;
      }
      elseif ($sibling['nid'] === $nid) {
        $next = TRUE;
      }
    }

    // Next of the parent...
    return $this->recurseParentSiblingsNext($parent['nid']);
  }

  /**
   * Find a parent node's next sibling, recursively.
   */
  private function recurseParentSiblingsNext($nid) {
    $next = FALSE;
    $grand_parent = $this->loadParent($nid);
    if (empty($grand_parent)) {
      return FALSE;
    }
    foreach ($this->loadChildren($grand_parent['nid'], 1) as $parent_sibling) {
      if ($next) {
        return $parent_sibling;
      }
      elseif ($parent_sibling['nid'] === $nid) {
        $next = TRUE;
      }
    }
    return $this->recurseParentSiblingsNext($grand_parent['nid']);
  }

  /**
   * {@inheritdoc}
   */
  public function getPrev() {
    return $this->loadPrev($this->node->id());
  }

  /**
   * Find a node's previous sibling, recursively.
   */
  private function loadPrev($nid) {
    $parent = $this->loadParent($nid);
    // No Parent => No Sibling.
    if (empty($parent)) {
      return FALSE;
    }
    $prev = [];
    // Siblings.
    foreach ($this->loadChildren($parent['nid'], 1) as $sibling) {
      if ($sibling['nid'] === $this->node->id()) {
        if (empty($prev)) {
          // Node was first among children, return parent as previous.
          return $parent;
        }
        // Find the last child of the sibling we saw last.
        return $this->findLastDecedent($prev);
      }
      else {
        $prev = $sibling;
      }
    }
    // If we never returned a prev, try the parent's siblings...
    return $this->loadPrev($parent['nid']);
  }

  /**
   * Find the deepest node in the tree based on heaviest child per level.
   */
  private function findLastDecedent($parent) {
    $child_query = \Drupal::database()->select('node_field_data', 'n');
    $child_query->join('node__' . $this->parentField, 'p', 'n.nid = p.entity_id');
    $child_query->join('node__' . $this->weightField, 'w', 'n.nid = w.entity_id');
    $child_query->fields('n', ['nid', 'title'])
      ->fields('w', [$this->weightField . '_value'])
      ->condition('p.' . $this->parentField . '_target_id', $parent['nid'])
      ->orderBy('w.' . $this->weightField . '_value', 'DESC');
    $heaviest_weight_child = $child_query->execute()->fetchAssoc();
    if (empty($heaviest_weight_child)) {
      return $parent;
    }
    $heaviest_weight_child['url'] = Url::fromRoute('entity.node.canonical',
      ['node' => $child['nid']],
      ['absolute' => TRUE]
    );
    return $this->findLastDecedent($heaviest_weight_child);
  }

}
