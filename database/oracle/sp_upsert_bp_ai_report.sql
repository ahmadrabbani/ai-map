CREATE OR REPLACE PROCEDURE SP_UPSERT_BP_AI_REPORT (
    P_APPLICATION_NO       IN VARCHAR2,
    P_CASE_ID              IN NUMBER,
    P_BP_APPLICATION_ID    IN NUMBER,
    P_AI_STATUS            IN VARCHAR2,
    P_AI_RECOMMENDATION    IN VARCHAR2,
    P_AI_CONFIDENCE_SCORE  IN NUMBER,
    P_REPORT_MARKDOWN      IN CLOB,
    P_REPORT_HTML          IN CLOB,
    P_REPORT_JSON          IN CLOB,
    P_CREATED_BY           IN VARCHAR2,
    P_REPORT_ID            OUT NUMBER
)
AS
    V_REPORT_ID NUMBER;
BEGIN
    BEGIN
        SELECT REPORT_ID
          INTO V_REPORT_ID
          FROM BP_AI_REPORT_HEADER
         WHERE APPLICATION_NO = P_APPLICATION_NO
           FOR UPDATE;

        UPDATE BP_AI_REPORT_HEADER
           SET CASE_ID = P_CASE_ID,
               BP_APPLICATION_ID = P_BP_APPLICATION_ID,
               AI_STATUS = P_AI_STATUS,
               AI_RECOMMENDATION = P_AI_RECOMMENDATION,
               AI_CONFIDENCE_SCORE = P_AI_CONFIDENCE_SCORE,
               REPORT_MARKDOWN = P_REPORT_MARKDOWN,
               REPORT_HTML = P_REPORT_HTML,
               REPORT_JSON = P_REPORT_JSON,
               UPDATED_ON = SYSDATE,
               UPDATED_BY = P_CREATED_BY
         WHERE REPORT_ID = V_REPORT_ID;
    EXCEPTION
        WHEN NO_DATA_FOUND THEN
            INSERT INTO BP_AI_REPORT_HEADER (
                REPORT_ID,
                CASE_ID,
                APPLICATION_NO,
                BP_APPLICATION_ID,
                AI_STATUS,
                AI_RECOMMENDATION,
                AI_CONFIDENCE_SCORE,
                REPORT_MARKDOWN,
                REPORT_HTML,
                REPORT_JSON,
                CREATED_ON,
                CREATED_BY
            ) VALUES (
                NULL,
                P_CASE_ID,
                P_APPLICATION_NO,
                P_BP_APPLICATION_ID,
                P_AI_STATUS,
                P_AI_RECOMMENDATION,
                P_AI_CONFIDENCE_SCORE,
                P_REPORT_MARKDOWN,
                P_REPORT_HTML,
                P_REPORT_JSON,
                SYSDATE,
                P_CREATED_BY
            )
            RETURNING REPORT_ID INTO V_REPORT_ID;
    END;

    P_REPORT_ID := V_REPORT_ID;
END;
/
