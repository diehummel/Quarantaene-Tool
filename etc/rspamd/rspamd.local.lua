local logger = require "rspamd_logger"
local util = require "rspamd_util"

logger.info("QUARANTINE: === ULTRA BYPASS LOADED ===")

local quarantine_path = "/opt/quarantine/"

-- 1. BYPASS PREFILTER – HÖCHSTE PRIORITÄT – KOMMT ALS ERSTES!
rspamd_config:register_symbol({
    name = 'QUARANTINE_BYPASS',
    type = 'prefilter',
    priority = 999,  -- MAXIMAL!
    callback = function(task)
        local release = task:get_header('X-Quarantine-Release')
        local spam = task:get_header('X-Spam')

        if release and string.find(string.lower(tostring(release)), 'yes') then
            logger.info("BYPASS AKTIVIERT: X-Quarantine-Release: yes → ACCEPT + SCORE = -999")
            task:set_pre_result('accept', 'Quarantäne-Freigabe')
            task:set_metric_score('default', -999.0)
            return true
        end

        if spam and string.lower(tostring(spam)) == 'no' then
            logger.info("BYPASS AKTIVIERT: X-Spam: No → ACCEPT + SCORE = -999")
            task:set_pre_result('accept', 'X-Spam: No')
            task:set_metric_score('default', -999.0)
            return true
        end

        return nil
    end
})

-- 2. SAVE TO QUARANTINE – NUR WENN KEIN BYPASS!
local function save_to_quarantine(task)
    local qid = task:get_queue_id() or "noqid"
    local filename = quarantine_path .. "QUARANTINE_" .. qid .. "_" .. tostring(os.time()) .. ".eml"
    local content_data = task:get_content()
    if not content_data then return false end
    local content = tostring(content_data)
    local file = io.open(filename, "w")
    if not file then return false end
    file:write(content)
    file:close()
    os.execute("chmod 666 " .. filename)
    logger.info("QUARANTINE: Saved to " .. filename)
    return true
end

-- 3. POSTFILTER – SPEICHERN NUR BEI REJECT ODER HOHEM SCORE
rspamd_config:register_symbol({
    name = 'QUARANTINE_SAVE',
    score = 0.0,
    type = 'postfilter',
    priority = 10,
    callback = function(task)
        local score_result = task:get_metric_score('default')
        local score = score_result[1] or 0
        local action = task:get_metric_action('default')

        logger.info("QUARANTINE_SAVE: Score: " .. score .. ", Action: " .. tostring(action))

        if action == 'reject' or (score >= 10.0 and action ~= 'accept') then
            logger.info("QUARANTINE_SAVE: Saving email")
            save_to_quarantine(task)
        end
        return false
    end
})
