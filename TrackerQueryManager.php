<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/constants/clarassign-constants-database-connectors.php';

class TrackerQueryManager {
    /**
     * Fetch trackers for a given entity type and ID.
     * 
     * @param string $entityType 'case', 'assignment', 'investigation', or 'task'
     * @param int $entityId The primary key of the entity
     * @param string $trackerType 'leads', 'docs', 'collateral', 'notes', 'deadlines', 'informants'
     * @return array
     */
    public static function getTrackers(string $entityType, int $entityId, string $trackerType): array {
        if (!in_array($entityType, ['case', 'assignment', 'investigation', 'task'])) {
            throw new InvalidArgumentException("Invalid entity type: $entityType");
        }

        $isCandA = in_array($entityType, ['case', 'assignment']);
        $db = $isCandA ? ClarassignDB::getCandACnx() : ClarassignDB::getIandTCnx();
        $toolsDb = ClarassignDB::getProductionToolsCnx();
        
        $entityIdCol = "{$entityType}_id";

        // Handle Local Trackers (Stored directly in domain DB)
        if (in_array($trackerType, ['notes', 'deadlines'])) {
            $tableName = "{$entityType}_{$trackerType}";
            
            // Handle specific column names based on tracker type
            $cols = "*";
            $orderBy = "";
            if ($trackerType === 'notes') {
                $cols = "_id AS note_id, {$entityType}_note AS note_text, creation_datetime, datetime_of_entry";
                $orderBy = "ORDER BY creation_datetime ASC";
                if ($entityType === 'task') {
                    $cols = "_id AS note_id, task_note, creation_datetime";
                }
            } elseif ($trackerType === 'deadlines') {
                $cols = "_id AS deadline_id, deadline_datetime, status";
                $orderBy = "ORDER BY deadline_datetime ASC";
            }

            // Execute local query
            $stmt = $db->prepare("SELECT $cols FROM $tableName WHERE $entityIdCol = ? $orderBy");
            if (!$stmt) return [];
            
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $results = [];
            while ($row = $res->fetch_assoc()) {
                $results[] = $row;
            }
            $stmt->close();
            return $results;
        }

        // Handle Linked Tools (Stored in PRODUCTION_TOOLS, linked via junction table)
        $junctionTable = "{$entityType}_{$trackerType}";
        if ($trackerType === 'docs') $junctionTable = "{$entityType}_docs";
        
        $toolIdCol = match($trackerType) {
            'leads' => 'lead_id',
            'docs' => 'document_id',
            'collateral' => 'collateral_item_id',
            'informants' => 'informant_id',
            default => throw new InvalidArgumentException("Invalid tracker type: $trackerType")
        };

        // Step 1: Query Junction Table
        $stmt = $db->prepare("SELECT $toolIdCol FROM $junctionTable WHERE $entityIdCol = ?");
        if (!$stmt) return [];
        
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $junctions = [];
        while ($row = $res->fetch_assoc()) {
            $junctions[] = $row;
        }
        $stmt->close();
        
        if (empty($junctions)) return [];
        
        // Step 2: Query Tools Database
        $ids = array_column($junctions, $toolIdCol);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        
        $toolsQuery = match($trackerType) {
            'leads' => "SELECT _id AS lead_id, lead_name, description_of_lead, lead_type_id FROM leads WHERE _id IN ($placeholders)",
            'docs' => "SELECT _id AS document_id, document_name AS file_name, description FROM documents WHERE _id IN ($placeholders)",
            'collateral' => "SELECT _id AS collateral_item_id, item_name, item_description AS description FROM collateral_items WHERE _id IN ($placeholders)",
            'informants' => "SELECT informant_id, informant_name, description FROM informants WHERE informant_id IN ($placeholders)"
        };

        $stmtTools = $toolsDb->prepare($toolsQuery);
        if (!$stmtTools) return [];
        
        $stmtTools->bind_param($types, ...$ids);
        $stmtTools->execute();
        $resTools = $stmtTools->get_result();
        
        $results = [];
        while ($row = $resTools->fetch_assoc()) {
            $results[] = $row;
        }
        $stmtTools->close();
        
        return $results;
    }
}
