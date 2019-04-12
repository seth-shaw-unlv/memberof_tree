<?php

namespace Drupal\memberof_tree;

use Drupal\Core\Entity\EntityInterface;

/**
 * Provides an interface defining a member_of tree.
 */
interface MemberOfTreeInterface {

  /**
   * Finds all parents of a given node ID.
   *
   * @param int $nid
   *   Node ID to retrieve parents for.
   * @param bool $load_entities
   *   If TRUE, a full entity load will occur on the node objects. Otherwise
   *   they are partial objects storing only node IDs and titles.
   *   Defaults to FALSE.
   * @return array
   *   An array of term objects which are the parents of the term $tid.
   */
  public function getParents($load_entities = FALSE);

  /**
   * Gathers all children of a node.
   *
   * @param int $nid
   *   The node ID under which to gather children.
   * @param int $max_depth
   *   The number of levels of the decendency tree to return. Leave NULL to
   *   return all levels.
   * @param bool $load_entities
   *   If TRUE, a full entity load will occur on the node objects. Otherwise
   *   they are partial objects storing only node IDs and titles to save memory
   *   when gathering a large numbers of decendents. Defaults to FALSE.
   *
   * @return array
   *   An tree of children in an array, in the order they should be rendered.
   */
  public function getChildren($max_depth = NULL, $load_entities = FALSE);

  /**
   * Fetches the entity for the next sibling.
   *
   * @param int $nid
   *   Node ID to retrieve a next sibling for.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Next sibling entity.
   */
  public function getNext();

  /**
   * Fetches the entity for the previous sibling.
   *
   * @param int $nid
   *   Node ID to retrieve a next sibling for.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Previous sibling entity.
   */
  public function getPrev();

}
