<?php

namespace Drupal\memberof_tree;

use Drupal\Core\Entity\EntityInterface;

/**
 * Implements a member_of tree.
 */
class MemberOfTree implements MemberOfTreeInterface {

  protected $node;

  protected $parent_field;

  protected $weight_field;

  /**
   * Constructs a MemberOfTree object.
   */
  function __construct(int $nid) {
      // We at least need to load the entity given to get the bundle config.
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      print("Loading NODE $nid...\n");
      $this->node = $node_storage->load($nid);
      $bundle = $this->node->bundle();

      // Parent and weight fields.
      $this->parent_field = 'field_member_of';
      $this->weight_field = 'field_weight';
      $config = \Drupal::config('memberof_tree.settings');
      if (isset($config)){
        foreach ($config->get('bundle_parent_fields') as $mapping) {
          if ($mapping['bundle'] == $bundle){
            if (isset($mapping['parent_field'])) {
              $this->parent_field = $mapping['parent_field'];
            }
            if (isset($mapping['weight_field'])) {
              $this->weight_field = $mapping['weight_field'];
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
   } else {
     return $parents;
   }

  }

  private function loadParents($nid) {
    // print("LOADING PARENTS FOR $nid looking in $field...\n");
    $parent = $this->getParent($nid);
    if ($parent) {
      // print("FOUND PARENT FOR NID: ".$parent['nid'].' "'.$parent['title'].'"'."\n");
      $parent['url'] = \Drupal\Core\Url::fromRoute('entity.node.canonical',
        ['node' => $parent['nid']],
        ['absolute' => TRUE]
      );
      return array_merge($this->loadParents($parent['nid']), [$parent]);
    }

    return [];
  }

  private function getParent($nid) {
    // SELECT n.nid, n.title
    // FROM node_field_data AS n
    // INNER JOIN node__field_as_parent AS f ON f.field_as_parent_target_id = n.nid
    // WHERE f.entity_id = 10;
    $parent_query = \Drupal::database()->select('node_field_data', 'n');
    $parent_query->join('node__'.$this->parent_field, 'f', 'n.nid = f.'.$this->parent_field.'_target_id');
    $parent_query->fields('n', ['nid','title'])
      ->condition('f.entity_id', $nid);
    return $parent_query->execute()->fetchAssoc();
  }

  /**
   * {@inheritdoc}
   */
  public function getChildren($max_depth = NULL, $load_entities = FALSE) {
    return $this->loadChildren($this->node->id(), $max_depth);
  }

  private function loadChildren($nid, $max_depth = NULL) {
    // print("LOADING CHILDREN FOR $nid ...\n");
    // SELECT n.nid, n.title
    // FROM node_field_data AS n
    // INNER JOIN node__field_as_parent AS f ON f.entity_id = n.nid
    // INNER JOIN node__field_as_weight AS w ON n.nid = w.entity_id
    // WHERE f.field_as_parent_target_id = 125
    // ORDER BY w.field_as_weight_value, n.title
    $child_query = \Drupal::database()->select('node_field_data', 'n');
    $child_query->join('node__'.$this->parent_field, 'p', 'n.nid = p.entity_id');
    $child_query->join('node__'.$this->weight_field, 'w', 'n.nid = w.entity_id');
    $child_query->fields('n', ['nid','title'])
      ->fields('w', [$this->weight_field . '_value'])
      ->condition('p.'.$this->parent_field.'_target_id', $nid)
      ->orderBy('w.'.$this->weight_field . '_value');
    // print("QUERY: ".$child_query->__toString()."\n");
    $results = $child_query->execute();
    $children = [];
    foreach ($results->fetchAllAssoc('nid', \PDO::FETCH_ASSOC) as $child_nid => $child) {
      //URL
      $child['url'] = \Drupal\Core\Url::fromRoute('entity.node.canonical',
        ['node' => $child['nid']],
        ['absolute' => TRUE]
      );
      // print("CHILD: \n".print_r($child,TRUE)."\n");
      $grand_children = [];
      if ($max_depth === null ) {
        $grand_children = $this->loadChildren($child['nid'], null);
      } elseif ($max_depth > 1) {
        $next_depth = $max_depth - 1;
        print("REMAINING DEPTH: $next_depth\n");
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
    // print_r($this->node->get($this->parent_field)->target_id);
    $next = FALSE;
    // Siblings
    foreach ($this->loadChildren($this->node->get($this->parent_field)->target_id, 1) as $sibling) {
      if ($next){
        return $sibling;
      } elseif ($sibling['nid'] === $this->node->id()){
        $next = TRUE;
      }
    }
    // Next was never true, try the parent's siblings...
  }

  /**
   * {@inheritdoc}
   */
  public function getPrev() {
    // print_r($this->node->get($this->parent_field)->target_id);
    $prev = [];
    // Siblings
    foreach ($this->loadChildren($this->node->get($this->parent_field)->target_id, 1) as $sibling) {
      if ($sibling['nid'] === $this->node->id()){
        return $prev;
      } else {
        $prev = $sibling;
      }
    }
    // If we never returned a prev, try the parent's siblings...
  }

}
